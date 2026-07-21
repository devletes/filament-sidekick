<?php

namespace Devletes\Sidekick\Livewire;

use Devletes\Sidekick\Models\Attachment;
use Devletes\Sidekick\Models\Conversation;
use Devletes\Sidekick\Models\ConversationMessage;
use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\AttachmentStore;
use Devletes\Sidekick\Support\SidekickContext;
use Devletes\Sidekick\Support\ToolRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

// Deliberately NOT #[Lazy]: an eager render costs ~one exists() query and
// avoids a placeholder flash when the panel slides open. (The earlier
// rationale here — "Filament panels boot Alpine without the intersect
// plugin" — was a misdiagnosis: x-intersect works fine in Filament v5.6
// panels; the "never fires" repro ran in a hidden Chrome tab, where
// IntersectionObserver callbacks are never delivered.)
class ChatPanel extends Component
{
    use WithFileUploads;

    public ?string $conversationId = null;

    public string $draft = '';

    /** Transient Livewire temp uploads from the composer's file input. */
    public array $uploads = [];

    /** Persisted Attachment ids staged for the next message. */
    public array $staged = [];

    /** Transient Livewire temp uploads from the confirm card's file input. */
    public array $cardUploads = [];

    /** Persisted Attachment ids uploaded on the live confirm card. */
    public array $cardStaged = [];

    public ?string $uploadError = null;

    public function mount(): void
    {
        // Fresh context by default: the panel opens on a new chat and the
        // previous conversation is one click away (resumeConversation).
    }

    public function resumeConversation(): void
    {
        if (! auth()->check()) {
            return;
        }

        $this->conversationId = $this->scopeToProfile(
            Conversation::query()->forParticipant(auth()->user()),
        )
            ->latest('updated_at')
            ->value('id');
    }

    public function getListeners(): array
    {
        // The echo subscription lives in JS (sidekick.js): Filament boots
        // window.Echo in its own time, and Livewire's native `echo-`
        // listeners silently no-op when Echo isn't there yet. The bridge
        // pokes this plain listener on every broadcast instead.
        return ['sidekick-echo-nudge' => '$refresh'];
    }

    public function send(): void
    {
        $user = auth()->user();
        $text = Str::limit(trim($this->draft), (int) config('sidekick.max_prompt_length', 4000), '');
        $staged = $this->stagedRows();

        // A message can be text, files, or both — never neither.
        if (! $user || ($text === '' && $staged->isEmpty()) || ! config('sidekick.enabled')) {
            return;
        }

        $key = 'sidekick-send:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return;
        }

        RateLimiter::hit($key, 60);

        $conversation = $this->resolveConversation($user, $text !== '' ? $text : (string) $staged->first()?->name);

        // Dead runs (worker down, crash before failed() fired) would block
        // the composer forever — resolve them before checking for activity.
        $this->failStaleRuns($conversation);

        if ($conversation->runs()->active()->exists()) {
            return;
        }

        // A live confirm card must be answered (or expire) before chatting on.
        if ($this->activeAction() !== null) {
            return;
        }

        // Claim the staged files for this message: from here they are part of
        // the conversation record (and out of the prune command's reach).
        if ($staged->isNotEmpty()) {
            Attachment::query()
                ->forUser($user)
                ->whereIn('id', $staged->pluck('id'))
                ->update(['conversation_id' => $conversation->id, 'status' => Attachment::STATUS_SENT]);
        }

        $run = $conversation->runs()->create([
            'user_id' => $user->getAuthIdentifier(),
            'prompt' => $text,
            'attachments' => $staged->isNotEmpty() ? $staged->pluck('id')->values()->all() : null,
            'status' => Run::STATUS_QUEUED,
        ]);

        $jobClass = config('sidekick.jobs.run');
        $jobClass::dispatch($run->id);

        $this->draft = '';
        $this->staged = [];
        $this->uploadError = null;

        // Sending always returns the view to the end of the conversation,
        // even if the user had scrolled up into history.
        $this->dispatch('sidekick-jump-to-end');
    }

    public function retry(): void
    {
        $conversation = $this->conversation();

        if (! $conversation) {
            return;
        }

        $lastRun = $conversation->runs()->latest()->first();

        if (! $lastRun || $lastRun->status !== Run::STATUS_FAILED) {
            return;
        }

        $run = $conversation->runs()->create([
            'user_id' => $lastRun->user_id,
            'prompt' => $lastRun->prompt,
            'attachments' => $lastRun->attachments,
            'status' => Run::STATUS_QUEUED,
        ]);

        $jobClass = config('sidekick.jobs.run');
        $jobClass::dispatch($run->id);

        $this->dispatch('sidekick-jump-to-end');
    }

    /** Composer file input — files persist to Attachment rows immediately. */
    public function updatedUploads(): void
    {
        $this->stageUploads('uploads', 'staged');
    }

    /** Confirm-card file input. */
    public function updatedCardUploads(): void
    {
        $this->stageUploads('cardUploads', 'cardStaged');
    }

    public function removeAttachment(string $attachmentId): void
    {
        $this->discardStaged($attachmentId, 'staged');
    }

    public function removeCardAttachment(string $attachmentId): void
    {
        $this->discardStaged($attachmentId, 'cardStaged');
    }

    /**
     * Detach a chat-referenced attachment from the live proposal's payload.
     * The attachment itself is untouched — it's part of the conversation
     * record; only this action stops using it.
     */
    public function removeProposalAttachment(string $actionId, string $attachmentId): void
    {
        $action = $this->ownedAction($actionId);

        if (! $action || ! $action->isConfirmable()) {
            return;
        }

        $ids = array_values(array_filter((array) ($action->payload['attachment_ids'] ?? []), 'is_string'));

        if (! in_array($attachmentId, $ids, true)) {
            return;
        }

        $payload = $action->payload;
        $payload['attachment_ids'] = array_values(array_diff($ids, [$attachmentId]));

        $action->update(['payload' => $payload]);
    }

    protected function stageUploads(string $property, string $target): void
    {
        $this->uploadError = null;

        $files = is_array($this->{$property}) ? $this->{$property} : array_filter([$this->{$property}]);
        $this->{$property} = [];

        $user = auth()->user();
        $store = app(AttachmentStore::class);

        if (! $user || ! $store->enabled() || ! config('sidekick.enabled')) {
            return;
        }

        foreach ($files as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            if (count($this->{$target}) >= $store->maxFiles()) {
                $this->uploadError = 'You can attach up to '.$store->maxFiles().' files.';
                rescue(fn () => $file->delete(), report: false);

                continue;
            }

            try {
                $this->{$target}[] = $store->store($file, $user, $this->conversationId)->id;
            } catch (\InvalidArgumentException $e) {
                $this->uploadError = $e->getMessage();
            } catch (\Throwable $e) {
                // A vanished/unreadable temp file must degrade to a retry
                // hint, never a 500 that eats the whole panel update.
                report($e);
                $this->uploadError = 'That upload didn\'t come through — please try again.';
            } finally {
                // The Livewire temp copy is spent either way (same-disk
                // stores were already MOVED by storeAs; this covers the rest).
                rescue(fn () => $file->delete(), report: false);
            }
        }
    }

    protected function discardStaged(string $attachmentId, string $target): void
    {
        if (! auth()->check() || ! in_array($attachmentId, $this->{$target}, true)) {
            return;
        }

        Attachment::query()
            ->forUser(auth()->user())
            ->where('status', Attachment::STATUS_STAGED)
            ->whereKey($attachmentId)
            ->first()
            ?->deleteWithFile();

        $this->{$target} = array_values(array_diff($this->{$target}, [$attachmentId]));
    }

    /**
     * The staged ids re-proven against the database (ownership + still
     * staged), in staging order.
     *
     * @return \Illuminate\Support\Collection<int, Attachment>
     */
    protected function stagedRows(string $target = 'staged'): \Illuminate\Support\Collection
    {
        if ($this->{$target} === [] || ! auth()->check()) {
            return collect();
        }

        return Attachment::query()
            ->forUser(auth()->user())
            ->whereIn('id', $this->{$target})
            ->where('status', Attachment::STATUS_STAGED)
            ->get()
            ->sortBy(fn (Attachment $attachment) => array_search($attachment->id, $this->{$target}, true))
            ->values();
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->draft = '';
    }

    protected function resolveConversation(object $user, string $firstMessage): Conversation
    {
        $conversation = $this->conversation();

        if ($conversation) {
            return $conversation;
        }

        $conversation = new Conversation([
            ...app(SidekickContext::class)->attributes($user),
            'user_id' => $user->getAuthIdentifier(),
            'profile' => app(\Devletes\Sidekick\Support\Profiles::class)->current(),
            'title' => Str::limit($firstMessage, 60, preserveWords: true),
        ]);

        $conversation->id = (string) Str::uuid7();
        $conversation->save();

        $this->conversationId = $conversation->id;

        return $conversation;
    }

    protected function conversation(): ?Conversation
    {
        if (! $this->conversationId || ! auth()->check()) {
            return null;
        }

        // Re-scoped on every call: the id is client-provided state, so
        // ownership + context (including the panel's profile) must be
        // re-proven per request.
        return $this->scopeToProfile(
            Conversation::query()->forParticipant(auth()->user()),
        )
            ->whereKey($this->conversationId)
            ->first();
    }

    /**
     * Each panel's assistant only ever sees its own profile's conversations —
     * the admin-side assistant and the employee one don't share history.
     */
    protected function scopeToProfile(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $profile = app(\Devletes\Sidekick\Support\Profiles::class)->current();

        return $profile === null
            ? $query->whereNull('profile')
            : $query->where('profile', $profile);
    }

    protected function failStaleRuns(Conversation $conversation): void
    {
        $conversation->runs()
            ->active()
            ->where('updated_at', '<', now()->subSeconds((int) config('sidekick.stale_after', 240)))
            ->update([
                'status' => Run::STATUS_FAILED,
                'error' => 'The assistant took too long to respond.',
                'finished_at' => now(),
            ]);
    }

    public function render(): View
    {
        $conversation = $this->conversation();

        $messages = $conversation
            ? ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('id')
                ->limit((int) config('sidekick.display_limit', 60))
                ->get()
                ->reverse()
                ->values()
            : collect();

        $latestRun = $conversation?->runs()->latest()->first();

        if ($latestRun && $latestRun->isStale()) {
            $this->failStaleRuns($conversation);
            $latestRun->refresh();
        }

        $activeRun = $latestRun?->isActive() ? $latestRun : null;
        $failedRun = ($latestRun?->status === Run::STATUS_FAILED
            && $latestRun->finished_at?->gt(now()->subMinutes(10))) ? $latestRun : null;

        $this->consumeNavigation($latestRun);

        $pendingActions = $this->pendingActions();
        $activeAction = $pendingActions->first(fn ($action) => $action->isConfirmable());

        // One chronological stream: outcome lines sit where their action was
        // proposed, not clumped after the messages.
        $timeline = $messages
            ->map(fn ($message) => [
                'kind' => 'message',
                'sort' => sprintf('%012d.0.%s', $message->created_at?->getTimestamp() ?? 0, $message->id),
                'model' => $message,
            ])
            ->concat(
                $pendingActions
                    ->reject(fn ($action) => $action->isConfirmable())
                    ->map(fn ($action) => [
                        'kind' => 'action',
                        'sort' => sprintf('%012d.1.%s', $action->created_at?->getTimestamp() ?? 0, $action->id),
                        'model' => $action,
                    ])
            )
            ->sortBy('sort')
            ->values();

        $canResume = ! $this->conversationId
            && auth()->check()
            && $this->scopeToProfile(Conversation::query()->forParticipant(auth()->user()))->exists();

        $attachmentStore = app(AttachmentStore::class);

        return view('sidekick::chat-panel', [
            'messages' => $messages,
            'timeline' => $timeline,
            'canResume' => $canResume,
            'activeAction' => $activeAction,
            'activeRun' => $activeRun,
            'activeActivity' => $activeRun ? $this->presentActivity($activeRun->activity ?? []) : [],
            'failedRun' => $failedRun,
            'attachmentsEnabled' => $attachmentStore->enabled(),
            'attachmentsAccept' => $attachmentStore->acceptAttribute(),
            'stagedAttachments' => $this->stagedRows(),
            'cardAttachments' => $this->stagedRows('cardStaged'),
            'cardPayloadAttachments' => $activeAction ? $this->payloadAttachmentRows($activeAction) : collect(),
            'activeRunAttachments' => $activeRun ? $this->runAttachmentRows($activeRun) : collect(),
            'assistantName' => config('sidekick.assistant.name', 'Assistant'),
            'assistantDescription' => config('sidekick.assistant.description'),
            // Broadcast per USER: the panel mounts on a fresh conversation,
            // so the user id is the only stable subscription key.
            'echoChannel' => config('sidekick.broadcasting.enabled') && auth()->check()
                ? 'sidekick.user.'.auth()->id()
                : null,
            // With broadcasting on, echo nudges carry the stream and polling
            // drops back to a safety net.
            'pollInterval' => config('sidekick.broadcasting.enabled')
                ? config('sidekick.polling.while_broadcasting', '10s')
                : config('sidekick.polling.interval', '2s'),
        ]);
    }

    public function confirmAction(string $actionId): void
    {
        $action = $this->ownedAction($actionId);

        if (! $action || ! $action->isConfirmable()) {
            return;
        }

        $handler = app(\Devletes\Sidekick\Support\ActionRegistry::class)->handler($action->type);

        if (! $handler) {
            $action->update(['status' => \Devletes\Sidekick\Models\PendingAction::STATUS_FAILED, 'result' => 'No handler registered.']);
            $this->cardUploads = [];
            $this->cardStaged = [];

            return;
        }

        $payload = $this->payloadWithCardAttachments($action);

        // Server-side twin of the disabled Confirm button: a required upload
        // must be on the payload before the handler ever runs.
        if ($payload === null) {
            return;
        }

        try {
            // Execution happens HERE, under the user's real click + session —
            // the handler re-validates everything against live data.
            $result = $handler->execute($payload, auth()->user());

            $action->update([
                'status' => \Devletes\Sidekick\Models\PendingAction::STATUS_EXECUTED,
                'result' => \Illuminate\Support\Str::limit($result, 480),
                'executed_at' => now(),
            ]);

            $this->acknowledge($action, "Done — {$action->summary}. {$action->result}");
        } catch (\Throwable $e) {
            report($e);

            $action->update([
                'status' => \Devletes\Sidekick\Models\PendingAction::STATUS_FAILED,
                'result' => \Illuminate\Support\Str::limit($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Something went wrong executing this action.', 480),
            ]);

            $this->acknowledge($action, "That didn't go through — {$action->result}");
        } finally {
            // Either way the card leaves the dock; its upload state goes with it.
            $this->cardUploads = [];
            $this->cardStaged = [];
        }

        // The composer returns where the card was — put the outcome in view
        // and the cursor back in the field.
        $this->dispatch('sidekick-jump-to-end');
        $this->dispatch('sidekick-focus-composer');
    }

    public function cancelAction(string $actionId): void
    {
        $action = $this->ownedAction($actionId);

        if ($action && $action->isConfirmable()) {
            $action->update(['status' => \Devletes\Sidekick\Models\PendingAction::STATUS_CANCELLED]);

            // Files uploaded on the card served nothing — remove them.
            foreach ($this->stagedRows('cardStaged') as $attachment) {
                $attachment->deleteWithFile();
            }

            $this->cardUploads = [];
            $this->cardStaged = [];
            $this->uploadError = null;

            $this->acknowledge($action, "Okay, cancelled — {$action->summary}. Nothing was submitted.");

            $this->dispatch('sidekick-jump-to-end');
            $this->dispatch('sidekick-focus-composer');
        }
    }

    /**
     * The action payload with card-uploaded attachment ids merged into
     * `attachment_ids`, or null when a required upload is still missing.
     * Claiming happens here (status → sent, linked to the conversation) so
     * the files survive pruning for handlers that keep path references.
     */
    protected function payloadWithCardAttachments(\Devletes\Sidekick\Models\PendingAction $action): ?array
    {
        $payload = $action->payload ?? [];
        $cardRows = $this->stagedRows('cardStaged');

        if ($cardRows->isNotEmpty()) {
            Attachment::query()
                ->forUser(auth()->user())
                ->whereIn('id', $cardRows->pluck('id'))
                ->update(['conversation_id' => $action->conversation_id, 'status' => Attachment::STATUS_SENT]);

            $payload['attachment_ids'] = array_values(array_unique(array_merge(
                array_filter((array) ($payload['attachment_ids'] ?? []), 'is_string'),
                $cardRows->pluck('id')->all(),
            )));
        }

        if ($action->requiresUpload() && empty($payload['attachment_ids'])) {
            $this->uploadError = 'Attach the required file before confirming.';

            return null;
        }

        return $payload;
    }

    /**
     * Hardcoded (not LLM) acknowledgment, persisted as a real assistant
     * message: instant + deterministic, lands in history, and the model sees
     * it in context on the next turn.
     */
    protected function acknowledge(\Devletes\Sidekick\Models\PendingAction $action, string $text): void
    {
        $message = new ConversationMessage([
            'conversation_id' => $action->conversation_id,
            'user_id' => $action->user_id,
            'agent' => (string) config('sidekick.agent'),
            'role' => 'assistant',
            'content' => $text,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => ['sidekick_ack' => true],
        ]);
        $message->id = (string) \Illuminate\Support\Str::uuid7();
        $message->save();

        Conversation::query()->whereKey($action->conversation_id)->update(['updated_at' => now()]);
    }

    protected function ownedAction(string $actionId): ?\Devletes\Sidekick\Models\PendingAction
    {
        if (! auth()->check() || ! $this->conversationId) {
            return null;
        }

        return \Devletes\Sidekick\Models\PendingAction::query()
            ->whereKey($actionId)
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', auth()->id())
            ->first();
    }

    protected function activeAction(): ?\Devletes\Sidekick\Models\PendingAction
    {
        return $this->pendingActions()->first(
            fn (\Devletes\Sidekick\Models\PendingAction $action): bool => $action->isConfirmable(),
        );
    }

    /** @return \Illuminate\Support\Collection<int, \Devletes\Sidekick\Models\PendingAction> */
    protected function pendingActions(): \Illuminate\Support\Collection
    {
        if (! $this->conversationId) {
            return collect();
        }

        // Lazily expire overdue proposals so cards can't be confirmed stale.
        \Devletes\Sidekick\Models\PendingAction::query()
            ->where('conversation_id', $this->conversationId)
            ->where('status', \Devletes\Sidekick\Models\PendingAction::STATUS_PROPOSED)
            ->where('expires_at', '<', now())
            ->update(['status' => \Devletes\Sidekick\Models\PendingAction::STATUS_EXPIRED]);

        return \Devletes\Sidekick\Models\PendingAction::query()
            ->where('conversation_id', $this->conversationId)
            ->where('created_at', '>=', now()->subHours(2))
            ->latest('id')
            ->limit(4)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Attachments the proposal's payload already references (convention key
     * `attachment_ids`) — rendered as chips on the card in the same style as
     * files uploaded there, so all of an action's attachments read as one set.
     *
     * @return \Illuminate\Support\Collection<int, Attachment>
     */
    protected function payloadAttachmentRows(\Devletes\Sidekick\Models\PendingAction $action): \Illuminate\Support\Collection
    {
        $ids = array_values(array_filter((array) ($action->payload['attachment_ids'] ?? []), 'is_string'));

        if ($ids === [] || ! auth()->check()) {
            return collect();
        }

        return Attachment::query()
            ->forUser(auth()->user())
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Attachment $attachment) => array_search($attachment->id, $ids, true))
            ->values();
    }

    /** @return \Illuminate\Support\Collection<int, Attachment> */
    protected function runAttachmentRows(Run $run): \Illuminate\Support\Collection
    {
        $ids = array_values(array_filter((array) ($run->attachments ?? []), 'is_string'));

        if ($ids === [] || ! auth()->check()) {
            return collect();
        }

        return Attachment::query()
            ->forUser(auth()->user())
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Attachment $attachment) => array_search($attachment->id, $ids, true))
            ->values();
    }

    /** One-shot: the atomic null-out means exactly one panel instance redirects. */
    protected function consumeNavigation(?Run $run): void
    {
        if (! $run || $run->status !== Run::STATUS_COMPLETED || blank($run->navigate_to)) {
            return;
        }

        $url = $run->navigate_to;

        $claimed = Run::query()
            ->whereKey($run->id)
            ->where('navigate_to', $url)
            ->update(['navigate_to' => null]);

        if ($claimed === 1) {
            $this->dispatch('sidekick-navigate', url: $url);
        }
    }

    /**
     * Action buttons for an assistant message, derived from its persisted
     * PresentActions tool call and re-resolved (re-authorized) at render time.
     *
     * @return array<int, array{label: string, url: string, external: bool}>
     */
    public function messageActions(ConversationMessage $message): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $resolver = app(\Devletes\Sidekick\Contracts\ActionResolver::class);
        $buttons = [];

        foreach ($message->decodedToolCalls() as $call) {
            if (($call['name'] ?? null) !== 'PresentActions') {
                continue;
            }

            $arguments = $call['arguments'] ?? [];

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?: [];
            }

            foreach (($arguments['actions'] ?? []) as $action) {
                $label = trim((string) ($action['label'] ?? ''));

                if ($label === '') {
                    continue;
                }

                if (filled($action['url'] ?? null)) {
                    $buttons[] = ['label' => $label, 'url' => (string) $action['url'], 'external' => true];
                } elseif (filled($action['target'] ?? null)) {
                    $url = $resolver->resolve((string) $action['target'], $action['record'] ?? null, $user);

                    if ($url) {
                        $buttons[] = ['label' => $label, 'url' => $url, 'external' => false];
                    }
                }
            }
        }

        return array_slice($buttons, 0, 4);
    }

    /** @return array<int, array{label: string, done: bool}> */
    protected function presentActivity(array $activity): array
    {
        $registry = app(ToolRegistry::class);
        $entries = [];

        foreach ($activity as $entry) {
            if (($entry['type'] ?? null) === 'call') {
                $entries[$entry['name']] = [
                    'label' => $registry->labelFor($entry['name']) ?? 'Using '.$entry['name'],
                    'done' => false,
                ];
            } elseif (($entry['type'] ?? null) === 'result' && isset($entries[$entry['name']])) {
                $entries[$entry['name']]['done'] = true;
            }
        }

        return array_values($entries);
    }
}
