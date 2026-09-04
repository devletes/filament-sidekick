<?php

namespace Devletes\Sidekick\Jobs;

use Devletes\Sidekick\Contracts\UsageLimiter;
use Devletes\Sidekick\Events\RunUpdated;
use Devletes\Sidekick\Models\Attachment;
use Devletes\Sidekick\Models\ConversationMessage;
use Devletes\Sidekick\Models\Run;
use Devletes\Sidekick\Support\PanelContext;
use Devletes\Sidekick\Support\Profiles;
use Devletes\Sidekick\Support\RunContext;
use Devletes\Sidekick\Support\ToolRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Throwable;

class RunChatTurn implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    protected string $partial = '';

    protected bool $textSegmented = false;

    protected array $activity = [];

    protected array $usage = [];

    protected int $flushedLength = 0;

    protected float $lastFlushAt = 0;

    protected ?Collection $loadedAttachments = null;

    public function __construct(public string $runId, public ?array $filamentContext = null)
    {
        $this->onQueue(config('sidekick.jobs.queue'));

        // Must outlive the provider call; the queue's retry_after must in turn exceed this, or a slow turn gets redelivered mid-stream.
        $this->timeout = (int) config('sidekick.timeout', 120) + 60;

        // Captured at dispatch: the web request knows the serving panel + tenant; the queue worker doesn't.
        $this->filamentContext ??= PanelContext::capture();
    }

    public function handle(): void
    {
        $run = Run::query()->find($this->runId);

        if (! $run || $run->status !== Run::STATUS_QUEUED) {
            return;
        }

        // The turn runs under the profile its conversation was started in, regardless of which worker picks the job up.
        app(Profiles::class)->apply($run->conversation?->profile);

        // Restore the dispatching request's panel + tenant so panel/tenant-scoped tools behave as in the panel.
        PanelContext::apply(
            $this->filamentContext['panel'] ?? null,
            $this->filamentContext['tenant'] ?? null,
        );

        // Atomic claim: a redelivered job (retry_after < timeout) must never stream the same turn twice.
        if ($this->transition($run, Run::STATUS_QUEUED, ['status' => Run::STATUS_RUNNING, 'started_at' => now()]) !== 1) {
            return;
        }

        $run->refresh();
        $this->notify($run);

        // Authoritative usage-limit check (the panel's is only fail-fast UX); runs after context setup so limiters see the tenant.
        $denial = app(UsageLimiter::class)
            ->check($this->resolveUser($run), $run->conversation_id);

        if ($denial !== null) {
            $this->transition($run, Run::STATUS_RUNNING, [
                'status' => Run::STATUS_FAILED,
                'error' => Str::limit($denial, 480),
                'denied' => true,
                'finished_at' => now(),
            ]);
            $run->refresh();
            $this->notify($run);

            return;
        }

        // Fresh per-turn scratch state; tools write into it (e.g. navigation intent) and the outcome lands on the run below.
        app()->forgetInstance(RunContext::class);
        $context = app()->instance(
            RunContext::class,
            new RunContext,
        );
        $context->conversationId = $run->conversation_id;
        $context->runId = $run->id;

        try {
            $this->streamTurn($run);

            // By stream end the RememberConversation middleware has already persisted the user + assistant messages.
            $this->transition($run, Run::STATUS_RUNNING, [
                'status' => Run::STATUS_COMPLETED,
                'partial_content' => null,
                'activity' => $this->activity,
                'usage' => $this->usage,
                // Denormalised so limits and insights can sum in SQL rather than decoding every row's JSON.
                'tokens' => ($this->usage['prompt_tokens'] ?? 0) + ($this->usage['completion_tokens'] ?? 0),
                'navigate_to' => $context->navigateTo,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            $this->transition($run, Run::STATUS_RUNNING, [
                'status' => Run::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 480),
                'finished_at' => now(),
            ]);
        }

        $run->refresh();
        $this->notify($run);
    }

    /** Status-guarded update: never overwrite an outcome another worker already wrote. */
    protected function transition(Run $run, string $from, array $attributes): int
    {
        return Run::query()->whereKey($run->id)->where('status', $from)->update($attributes);
    }

    protected function streamTurn(Run $run): void
    {
        $agentClass = config('sidekick.agent');
        $agent = $agentClass::make();

        $agent->continue($run->conversation_id, $this->resolveUser($run));

        // Attachments ride as a metadata note on the prompt — file contents are never uploaded to the provider.
        $prompt = $this->composeModelPrompt($run);

        $stream = $agent->stream(
            $prompt,
            provider: config('sidekick.provider'),
            model: config('sidekick.model'),
            timeout: (int) config('sidekick.timeout', 120),
        );

        $this->partial = '';
        $this->textSegmented = false;

        foreach ($stream as $event) {
            if ($event instanceof TextDelta) {
                $this->partial .= $event->delta;
                $this->flush($run);
            } elseif ($event instanceof ToolCall) {
                // The reply resumes in a new content block after a tool call; without a separator the sentences jam together.
                if ($this->partial !== '' && ! str_ends_with($this->partial, "\n")) {
                    $this->partial .= "\n\n";
                    $this->textSegmented = true;
                }

                $this->activity[] = [
                    // In catalog mode every call arrives as RunTool; record what it actually ran so the
                    // panel shows "Checking your leave balance" rather than "Using RunTool".
                    'type' => 'call',
                    'name' => ToolRegistry::ranTool($event->toolCall->name, $event->toolCall->arguments),
                    'at' => now()->toIso8601String(),
                ];
                $this->flush($run, force: true);
            } elseif ($event instanceof ToolResult) {
                $this->activity[] = [
                    'type' => 'result',
                    'name' => ToolRegistry::ranTool($event->toolResult->name, $event->toolResult->arguments),
                    'successful' => $event->successful,
                    'at' => now()->toIso8601String(),
                ];
                $this->flush($run, force: true);
            } elseif ($event instanceof StreamEnd) {
                $this->usage = [
                    'prompt_tokens' => ($this->usage['prompt_tokens'] ?? 0) + $event->usage->promptTokens,
                    'completion_tokens' => ($this->usage['completion_tokens'] ?? 0) + $event->usage->completionTokens,
                ];
            }
        }

        $this->repairStoredReply($run);
        $this->repairStoredUserMessage($run, $prompt);
    }

    /** Swap the composed prompt back for the user's clean text and record attachment metadata on the message row. */
    protected function repairStoredUserMessage(Run $run, string $sentPrompt): void
    {
        $attachments = $this->attachmentRows($run);

        if ($attachments->isEmpty()) {
            return;
        }

        $message = ConversationMessage::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('role', 'user')
            ->latest('id')
            ->first();

        if (! $message || $message->content !== $sentPrompt) {
            return;
        }

        $message->update([
            'content' => $run->prompt,
            'attachments' => $attachments
                ->map(fn (Attachment $attachment): array => $attachment->toMetadata())
                ->values()
                ->all(),
        ]);
    }

    protected function composeModelPrompt(Run $run): string
    {
        $note = $this->attachmentNote($run);

        if ($note === '') {
            return $run->prompt;
        }

        return trim($run->prompt) === '' ? $note : $run->prompt."\n\n".$note;
    }

    protected function attachmentNote(Run $run): string
    {
        $attachments = $this->attachmentRows($run);

        if ($attachments->isEmpty()) {
            return '';
        }

        $files = $attachments
            ->map(fn (Attachment $attachment): string => "\"{$attachment->name}\" ({$attachment->mime}, {$attachment->humanSize()}, attachment_id: {$attachment->id})")
            ->join('; ');

        return '[The user attached '.$attachments->count().' file(s): '.$files
            .'. File contents are NOT visible to you — never claim or guess what is inside; you only know the names, types, and sizes.'
            .' If the user has not said what to do with them, ask.'
            .' Pass attachment_id values to tools or actions that accept them.]';
    }

    /** @return Collection<int, Attachment> */
    protected function attachmentRows(Run $run): Collection
    {
        if ($this->loadedAttachments !== null) {
            return $this->loadedAttachments;
        }

        $ids = array_values(array_filter((array) ($run->attachments ?? []), 'is_string'));

        if ($ids === []) {
            return $this->loadedAttachments = collect();
        }

        // Scoped to the run's user: ids are stored input, ownership is re-proven.
        return $this->loadedAttachments = Attachment::query()
            ->where('user_id', $run->user_id)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Attachment $attachment) => array_search($attachment->id, $ids, true))
            ->values();
    }

    /** The store joins reply text blocks with no separator; mirror the streamed paragraph breaks into the persisted message. */
    protected function repairStoredReply(Run $run): void
    {
        if (! $this->textSegmented) {
            return;
        }

        $message = ConversationMessage::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $repaired = rtrim($this->partial);

        if ($message && $repaired !== '' && $message->content !== $repaired) {
            $message->update(['content' => $repaired]);
        }
    }

    /** Persist streamed progress in coarse chunks; every write also refreshes the staleness heartbeat. */
    protected function flush(Run $run, bool $force = false): void
    {
        $now = microtime(true);

        if (! $force
            && strlen($this->partial) - $this->flushedLength < 80
            && $now - $this->lastFlushAt < 0.5) {
            return;
        }

        $this->flushedLength = strlen($this->partial);
        $this->lastFlushAt = $now;

        $run->update(['partial_content' => $this->partial, 'activity' => $this->activity]);
        $this->notify($run);
    }

    /** Resolved through the dispatching panel's guard — panels often use their own user model. */
    protected function resolveUser(Run $run): object
    {
        $model = PanelContext::userModel($this->filamentContext['guard'] ?? null);

        return $model::query()->findOrFail($run->user_id);
    }

    /** Broadcast failures (Reverb down, misconfig) must never kill the turn. */
    protected function notify(Run $run): void
    {
        if (! config('sidekick.broadcasting.enabled')) {
            return;
        }

        rescue(fn () => broadcast(new RunUpdated($run->user_id, $run->conversation_id, $run->id, $run->status)), report: false);
    }

    public function failed(?Throwable $e): void
    {
        // Guarded: a redelivered job reaching failed() must not stomp a still-streaming or finished attempt's outcome.
        Run::query()
            ->whereKey($this->runId)
            ->whereIn('status', [Run::STATUS_QUEUED, Run::STATUS_RUNNING])
            ->update([
                'status' => Run::STATUS_FAILED,
                'error' => Str::limit($e?->getMessage() ?? 'The assistant run failed.', 480),
                'finished_at' => now(),
            ]);
    }
}
