<?php

namespace Devletes\Sidekick\Livewire;

use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Contracts\UsageLimiter;
use Devletes\Sidekick\Models\Attachment;
use Devletes\Sidekick\Models\Conversation;
use Devletes\Sidekick\Models\ConversationMessage;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\ActionRegistry;
use Devletes\Sidekick\Support\AttachmentStore;
use Devletes\Sidekick\Support\Profiles;
use Devletes\Sidekick\Support\SidekickContext;
use Devletes\Sidekick\Support\ToolRegistry;
use Filament\Models\Contracts\HasName;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

// Deliberately NOT #[Lazy]: an eager render costs ~one exists() query and avoids a placeholder flash on open.
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

    /** Whether the modal-mode confirm card is currently on screen. */
    public bool $actionModalOpen = false;

    /** The modal-mode action last seen by a render, so only a newly proposed one re-opens the modal. */
    public ?string $actionModalId = null;

    /** False until the first render, which is how a page load is told apart from a proposal arriving live. */
    public bool $actionModalPrimed = false;

    public function mount(): void
    {
        // Deliberately empty: the panel opens on a fresh chat; the last conversation is one click away (resumeConversation).
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

        $this->forgetActionModal();
    }

    public function getListeners(): array
    {
        // Echo subscription lives in JS (sidekick.js): Livewire's native `echo-` listeners silently no-op when window.Echo boots late.
        return ['sidekick-echo-nudge' => '$refresh'];
    }

    public function send(): void
    {
        $user = auth()->user();
        $text = Str::limit(trim($this->draft), (int) config('sidekick.max_prompt_length', 4000), '');
        $staged = $this->stagedRows();

        if (! $user || ($text === '' && $staged->isEmpty()) || ! config('sidekick.enabled')) {
            return;
        }

        $key = 'sidekick-send:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return;
        }

        RateLimiter::hit($key, 60);

        $conversation = $this->resolveConversation($user, $text !== '' ? $text : (string) $staged->first()?->name);

        // Dead runs (worker crash before failed() fired) would block the composer forever — fail them before the active check.
        $this->failStaleRuns($conversation);

        if ($conversation->runs()->active()->exists()) {
            return;
        }

        // A live confirm card must be answered (or expire) before chatting on.
        if ($this->activeAction() !== null) {
            return;
        }

        // A usage-limiter denial lands as a failed run (renders like any run error) without queueing; staged files stay staged.
        $denial = app(UsageLimiter::class)->check($user, $conversation->id);

        if ($denial !== null) {
            $conversation->runs()->create([
                'user_id' => $user->getAuthIdentifier(),
                'prompt' => $text,
                'status' => Run::STATUS_FAILED,
                'error' => Str::limit($denial, 480),
                'denied' => true,
                'finished_at' => now(),
            ]);

            $this->draft = '';
            $this->dispatch('sidekick-jump-to-end');

            return;
        }

        // Claiming links the files to the conversation and puts them out of the prune command's reach.
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

        $this->dispatch('sidekick-jump-to-end');
    }

    public function retry(): void
    {
        $conversation = $this->conversation();

        if (! $conversation) {
            return;
        }

        $lastRun = $conversation->runs()->latest()->first();

        // Denied runs are a policy outcome, not a glitch — retrying just hammers the limiter.
        if (! $lastRun || $lastRun->status !== Run::STATUS_FAILED || $lastRun->denied) {
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

    /** Detach an attachment from the live proposal's payload; the attachment itself stays in the conversation record. */
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
                // A vanished temp file must degrade to a retry hint, never a 500 that eats the whole panel update.
                report($e);
                $this->uploadError = 'That upload didn\'t come through — please try again.';
            } finally {
                // The Livewire temp copy is spent either way (same-disk stores already moved it; this covers the rest).
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
     * The staged ids re-proven against the database (ownership + still staged), in staging order.
     *
     * @return Collection<int, Attachment>
     */
    protected function stagedRows(string $target = 'staged'): Collection
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
        $this->forgetActionModal();
    }

    public function updatedConversationId(): void
    {
        $this->forgetActionModal();
    }

    /** Re-prime on a conversation switch: a card already pending there gets the dock link, not a modal springing open. */
    protected function forgetActionModal(): void
    {
        $this->actionModalOpen = false;
        $this->actionModalId = null;
        $this->actionModalPrimed = false;
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
            'profile' => app(Profiles::class)->current(),
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

        // The id is client-provided state: ownership + profile scope must be re-proven per request.
        return $this->scopeToProfile(
            Conversation::query()->forParticipant(auth()->user()),
        )
            ->whereKey($this->conversationId)
            ->first();
    }

    /** Each panel's assistant only sees its own profile's conversations — different panels don't share history. */
    protected function scopeToProfile(Builder $query): Builder
    {
        $profile = app(Profiles::class)->current();

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

        // A modal card opens itself when proposed mid-session. On a page load the
        // component is unprimed, so it stays shut and the dock offers a link back
        // in — an unexpected modal on load reads as a trap.
        if ($activeAction?->rendersInModal() && $this->actionModalPrimed && $this->actionModalId !== $activeAction->id) {
            $this->actionModalOpen = true;
        }

        $this->actionModalId = $activeAction?->rendersInModal() ? $activeAction->id : null;
        $this->actionModalPrimed = true;

        // One chronological stream: outcome lines sort where their action was proposed, not clumped after the messages.
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
            'assistantName' => $assistantName = config('sidekick.assistant.name', 'Assistant'),
            'assistantDescription' => config('sidekick.assistant.description'),
            'greeting' => $this->greeting($assistantName),
            'maxPromptLength' => (int) config('sidekick.max_prompt_length', 4000),
            'actionModalId' => $this->getId().'-action',
            // Broadcast per user: the panel mounts on a fresh conversation, so the user id is the only stable subscription key.
            'echoChannel' => config('sidekick.broadcasting.enabled') && auth()->check()
                ? 'sidekick.user.'.auth()->id()
                : null,
            // With broadcasting on, echo nudges carry the stream and polling drops back to a safety net.
            'pollInterval' => config('sidekick.broadcasting.enabled')
                ? config('sidekick.polling.while_broadcasting', '10s')
                : config('sidekick.polling.interval', '2s'),
        ]);
    }

    /** Greets by first name when the user model exposes one, in any of Filament's usual shapes. */
    protected function greeting(string $assistantName): string
    {
        $user = auth()->user();

        $name = match (true) {
            $user instanceof HasName => $user->getFilamentName(),
            default => (string) (($user?->name ?? $user?->first_name) ?? ''),
        };

        $firstName = trim(strtok(trim($name), ' ') ?: '');

        return $firstName === ''
            ? "I'm {$assistantName}"
            : "Hi {$firstName} — I'm {$assistantName}";
    }

    /** Re-open a modal confirmation the user escaped by reloading the page. */
    public function openActionModal(): void
    {
        $action = $this->activeAction();

        if ($action?->rendersInModal()) {
            $this->actionModalOpen = true;
        }
    }

    public function confirmAction(string $actionId): void
    {
        $action = $this->ownedAction($actionId);

        if (! $action || ! $action->isConfirmable()) {
            return;
        }

        $handler = app(ActionRegistry::class)->handler($action->type);

        if (! $handler) {
            $action->update(['status' => PendingAction::STATUS_FAILED, 'result' => 'No handler registered.']);
            $this->cardUploads = [];
            $this->cardStaged = [];

            return;
        }

        $payload = $this->payloadWithCardAttachments($action);

        // Server-side twin of the disabled Confirm button: a required upload must be present before the handler runs.
        if ($payload === null) {
            return;
        }

        // Atomic claim: concurrent clicks (e.g. a second tab) must not both reach execute().
        $claimed = PendingAction::query()
            ->whereKey($action->id)
            ->where('status', PendingAction::STATUS_PROPOSED)
            ->update(['status' => PendingAction::STATUS_EXECUTING]);

        if ($claimed !== 1) {
            return;
        }

        try {
            // Executes under the user's real click + session; the handler re-validates against live data.
            $result = $handler->execute($payload, auth()->user());

            $action->update([
                'status' => PendingAction::STATUS_EXECUTED,
                'result' => Str::limit($result, 480),
                'executed_at' => now(),
            ]);

            $this->acknowledge($action, "Done — {$action->summary}. {$action->result}");
        } catch (\Throwable $e) {
            report($e);

            $action->update([
                'status' => PendingAction::STATUS_FAILED,
                'result' => Str::limit($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Something went wrong executing this action.', 480),
            ]);

            $this->acknowledge($action, "That didn't go through — {$action->result}");
        } finally {
            // Either way the card leaves the screen; its upload state goes with it.
            $this->cardUploads = [];
            $this->cardStaged = [];
            $this->actionModalOpen = false;
        }

        $this->dispatch('sidekick-jump-to-end');
        $this->dispatch('sidekick-focus-composer');
    }

    public function cancelAction(string $actionId): void
    {
        $action = $this->ownedAction($actionId);

        if ($action && $action->isConfirmable()) {
            $action->update(['status' => PendingAction::STATUS_CANCELLED]);

            // Files uploaded on the card served nothing — remove them.
            foreach ($this->stagedRows('cardStaged') as $attachment) {
                $attachment->deleteWithFile();
            }

            $this->cardUploads = [];
            $this->cardStaged = [];
            $this->uploadError = null;
            $this->actionModalOpen = false;

            $this->acknowledge($action, "Okay, cancelled — {$action->summary}. Nothing was submitted.");

            $this->dispatch('sidekick-jump-to-end');
            $this->dispatch('sidekick-focus-composer');
        }
    }

    /** Payload with card-uploaded ids merged into `attachment_ids` (claimed as sent so they survive pruning), or null when a required upload is missing. */
    protected function payloadWithCardAttachments(PendingAction $action): ?array
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

    /** Hardcoded (not LLM) acknowledgment persisted as a real assistant message so the model sees it in context next turn. */
    protected function acknowledge(PendingAction $action, string $text): void
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
        $message->id = (string) Str::uuid7();
        $message->save();

        Conversation::query()->whereKey($action->conversation_id)->update(['updated_at' => now()]);
    }

    protected function ownedAction(string $actionId): ?PendingAction
    {
        if (! auth()->check() || ! $this->conversationId) {
            return null;
        }

        return PendingAction::query()
            ->whereKey($actionId)
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', auth()->id())
            ->first();
    }

    protected function activeAction(): ?PendingAction
    {
        return $this->pendingActions()->first(
            fn (PendingAction $action): bool => $action->isConfirmable(),
        );
    }

    /** @return Collection<int, PendingAction> */
    protected function pendingActions(): Collection
    {
        // Scoped to the signed-in user, not just the client-supplied conversation id — a leaked id must never render someone else's cards.
        if (! $this->conversationId || ! auth()->check()) {
            return collect();
        }

        $owned = fn (): Builder => PendingAction::query()
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', auth()->id());

        // Lazily expire overdue proposals so cards can't be confirmed stale.
        $owned()
            ->where('status', PendingAction::STATUS_PROPOSED)
            ->where('expires_at', '<', now())
            ->update(['status' => PendingAction::STATUS_EXPIRED]);

        return $owned()
            ->where('created_at', '>=', now()->subHours(2))
            ->latest('id')
            ->limit(4)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Attachments the proposal's payload references (`attachment_ids`), rendered as chips on the card.
     *
     * @return Collection<int, Attachment>
     */
    protected function payloadAttachmentRows(PendingAction $action): Collection
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

    /** @return Collection<int, Attachment> */
    protected function runAttachmentRows(Run $run): Collection
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

        // Resuming an old conversation must not fire its long-past redirect.
        if (! $this->navigationIsFresh($run)) {
            Run::query()->whereKey($run->id)->update(['navigate_to' => null]);

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

    /** True when the run finished recently enough that a redirect still makes sense. */
    protected function navigationIsFresh(Run $run): bool
    {
        return $run->finished_at !== null && $run->finished_at->gt(now()->subMinutes(2));
    }

    /**
     * Action buttons from the message's persisted PresentActions tool call, re-resolved (re-authorized) at render time.
     *
     * @return array<int, array{label: string, url: string, external: bool}>
     */
    public function messageActions(ConversationMessage $message): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $resolver = app(ActionResolver::class);
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
                    // Model-authored: only ever http(s), never javascript: or data:.
                    $url = (string) $action['url'];

                    if (in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                        $buttons[] = ['label' => $label, 'url' => $url, 'external' => true];
                    }
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
