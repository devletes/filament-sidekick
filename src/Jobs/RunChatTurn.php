<?php

namespace Devletes\Sidekick\Jobs;

use Devletes\Sidekick\Events\RunUpdated;
use Devletes\Sidekick\Models\Run;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    protected ?\Illuminate\Support\Collection $loadedAttachments = null;

    public function __construct(public string $runId)
    {
        $this->onQueue(config('sidekick.jobs.queue'));
    }

    public function handle(): void
    {
        $run = Run::query()->find($this->runId);

        if (! $run || $run->status !== Run::STATUS_QUEUED) {
            return;
        }

        // The turn runs under the profile its conversation was started in —
        // identity, instructions, tools, and model all follow the panel that
        // opened the chat, regardless of which worker picks the job up.
        app(\Devletes\Sidekick\Support\Profiles::class)->apply($run->conversation?->profile);

        $run->update(['status' => Run::STATUS_RUNNING, 'started_at' => now()]);
        $this->notify($run);

        // Fresh per-turn scratch state; tools write into it (e.g. navigation
        // intent) and the outcome is persisted onto the run below.
        app()->forgetInstance(\Devletes\Sidekick\Support\RunContext::class);
        $context = app()->instance(
            \Devletes\Sidekick\Support\RunContext::class,
            new \Devletes\Sidekick\Support\RunContext,
        );
        $context->conversationId = $run->conversation_id;
        $context->runId = $run->id;

        try {
            $this->streamTurn($run);

            // The RememberConversation middleware has already persisted the
            // user + assistant messages by the time the stream is exhausted.
            $run->update([
                'status' => Run::STATUS_COMPLETED,
                'partial_content' => null,
                'activity' => $this->activity,
                'usage' => $this->usage,
                'navigate_to' => $context->navigateTo,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            $run->update([
                'status' => Run::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 480),
                'finished_at' => now(),
            ]);
        }

        $this->notify($run);
    }

    protected function streamTurn(Run $run): void
    {
        $agentClass = config('sidekick.agent');
        $agent = $agentClass::make();

        $agent->continue($run->conversation_id, $this->resolveUser($run));

        // Attachments ride as a metadata note appended to the prompt — file
        // contents are never uploaded to the provider.
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
                // The reply resumes in a new content block after a tool call;
                // combined without a separator the sentences jam together
                // ("...(1 used).Want to..."). Break the paragraph here and
                // mirror the fix into the stored message afterwards.
                if ($this->partial !== '' && ! str_ends_with($this->partial, "\n")) {
                    $this->partial .= "\n\n";
                    $this->textSegmented = true;
                }

                $this->activity[] = [
                    'type' => 'call',
                    'name' => $event->toolCall->name,
                    'at' => now()->toIso8601String(),
                ];
                $this->flush($run, force: true);
            } elseif ($event instanceof ToolResult) {
                $this->activity[] = [
                    'type' => 'result',
                    'name' => $event->toolResult->name,
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

    /**
     * The metadata note is model-facing plumbing; the persisted user message
     * should read as the user wrote it. Swap the composed prompt back for the
     * clean text and record the attachment metadata on the message row (the
     * panel renders chips from it, and rehydration re-appends the note).
     */
    protected function repairStoredUserMessage(Run $run, string $sentPrompt): void
    {
        $attachments = $this->attachmentRows($run);

        if ($attachments->isEmpty()) {
            return;
        }

        $message = \Devletes\Sidekick\Models\ConversationMessage::query()
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
                ->map(fn (\Devletes\Sidekick\Models\Attachment $attachment): array => $attachment->toMetadata())
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
            ->map(fn (\Devletes\Sidekick\Models\Attachment $attachment): string => "\"{$attachment->name}\" ({$attachment->mime}, {$attachment->humanSize()}, attachment_id: {$attachment->id})")
            ->join('; ');

        return '[The user attached '.$attachments->count().' file(s): '.$files
            .'. File contents are NOT visible to you — never claim or guess what is inside; you only know the names, types, and sizes.'
            .' If the user has not said what to do with them, ask.'
            .' Pass attachment_id values to tools or actions that accept them.]';
    }

    /** @return \Illuminate\Support\Collection<int, \Devletes\Sidekick\Models\Attachment> */
    protected function attachmentRows(Run $run): \Illuminate\Support\Collection
    {
        if ($this->loadedAttachments !== null) {
            return $this->loadedAttachments;
        }

        $ids = array_values(array_filter((array) ($run->attachments ?? []), 'is_string'));

        if ($ids === []) {
            return $this->loadedAttachments = collect();
        }

        // Scoped to the run's user: ids are stored input, ownership is re-proven.
        return $this->loadedAttachments = \Devletes\Sidekick\Models\Attachment::query()
            ->where('user_id', $run->user_id)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (\Devletes\Sidekick\Models\Attachment $attachment) => array_search($attachment->id, $ids, true))
            ->values();
    }

    /**
     * The conversation store joins the reply's text blocks with no separator;
     * when tool calls split the reply we injected paragraph breaks into the
     * streamed copy — mirror them into the persisted message.
     */
    protected function repairStoredReply(Run $run): void
    {
        if (! $this->textSegmented) {
            return;
        }

        $message = \Devletes\Sidekick\Models\ConversationMessage::query()
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

    protected function resolveUser(Run $run): object
    {
        $model = config('auth.providers.users.model');

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
        Run::query()->whereKey($this->runId)->update([
            'status' => Run::STATUS_FAILED,
            'error' => Str::limit($e?->getMessage() ?? 'The assistant run failed.', 480),
            'finished_at' => now(),
        ]);
    }
}
