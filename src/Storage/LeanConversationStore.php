<?php

namespace Devletes\Sidekick\Storage;

use Devletes\Sidekick\Support\TokenBudget;
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Rehydrates history lean: past tool calls are kept, but a long result is replaced by a stub (the model calls the
 * tool again for fresh data). Writes keep the full record.
 *
 * The calls themselves have to stay. A past reply like "Review the card, then Confirm" only makes sense next to the
 * proposal that produced the card; with the call stripped, the model learns from its own history that the line is
 * enough on its own, and writes it without proposing anything.
 */
class LeanConversationStore extends DatabaseConversationStore
{
    /** Results up to this long are kept as they were: a proposal's outcome, a short answer. */
    public const KEPT_RESULT_LENGTH = 400;

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->trimToBudget($this->rehydrate($conversationId, $limit))
            // Trimming can cut a call from its results; a result with no call before it is refused by providers.
            ->skipWhile(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ->values();
    }

    /** @return Collection<int, Message> */
    protected function rehydrate(string $conversationId, int $limit): Collection
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function ($record): array {
                $content = (string) $record->content;

                // Re-append the attachment note so files from past turns stay referencable without contents entering context.
                if ($record->role === 'user') {
                    $note = $this->attachmentNote($record->attachments ?? null);

                    if ($note !== '') {
                        $content = trim($content) === '' ? $note : $content."\n\n".$note;
                    }

                    return trim($content) === '' ? [] : [new Message('user', $content)];
                }

                $messages = $this->toolTurn($record);

                if (trim($content) !== '') {
                    $messages[] = new AssistantMessage($content);
                }

                return $messages;
            });
    }

    /**
     * A reply's tool calls, each with its result, as the call and result messages the provider expects. Only calls
     * whose result was recorded are replayed, since a call without one is refused.
     *
     * @return array<int, Message>
     */
    protected function toolTurn(object $record): array
    {
        $calls = collect(json_decode((string) ($record->tool_calls ?? '[]'), true))->filter(fn ($call): bool => is_array($call) && filled($call['id'] ?? null));
        $results = collect(json_decode((string) ($record->tool_results ?? '[]'), true))->filter(fn ($result): bool => is_array($result))->keyBy('id');

        $answered = $calls->filter(fn (array $call): bool => $results->has($call['id']))->values();

        if ($answered->isEmpty()) {
            return [];
        }

        return [
            new AssistantMessage('', $answered->map(fn (array $call): ToolCall => ToolCall::fromArray($call))->values()),
            new ToolResultMessage($answered->map(fn (array $call): ToolResult => ToolResult::fromArray(
                $this->leanResult($results[$call['id']]),
            ))->values()),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function leanResult(array $result): array
    {
        $text = is_string($result['result'] ?? null) ? $result['result'] : json_encode($result['result'] ?? null);

        if (mb_strlen((string) $text) > self::KEPT_RESULT_LENGTH) {
            $result['result'] = '(Not kept in history. Call the tool again for current data.)';
        }

        return $result;
    }

    /**
     * A row cap alone says nothing about prompt size — ten pasted logs dwarf ten "thanks". When a token budget
     * is set, drop whole messages newest-first-inwards until the estimate fits.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    protected function trimToBudget(Collection $messages): Collection
    {
        $budget = config('sidekick.history_token_budget');

        if ($budget === null) {
            return $messages;
        }

        $budget = max(0, (int) $budget);
        $kept = [];
        $spent = 0;

        foreach ($messages->reverse() as $message) {
            $cost = TokenBudget::estimate((string) $message->content
                .match (true) {
                    $message instanceof ToolResultMessage => json_encode($message->toolResults->all()),
                    $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty() => json_encode($message->toolCalls->all()),
                    default => '',
                });

            // Always keep the most recent message: a single oversized turn should shrink history, not erase it.
            if ($kept !== [] && $spent + $cost > $budget) {
                break;
            }

            $kept[] = $message;
            $spent += $cost;
        }

        return collect(array_reverse($kept));
    }

    protected function attachmentNote(mixed $attachments): string
    {
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true);
        }

        if (! is_array($attachments) || $attachments === []) {
            return '';
        }

        $files = collect($attachments)
            ->filter(fn ($entry): bool => is_array($entry) && filled($entry['name'] ?? null))
            ->map(fn (array $entry): string => '"'.$entry['name'].'"'
                .(filled($entry['id'] ?? null) ? ' (attachment_id: '.$entry['id'].')' : ''))
            ->join('; ');

        if ($files === '') {
            return '';
        }

        return '[Attached with this message (contents not visible to you): '.$files.']';
    }
}
