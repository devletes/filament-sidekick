<?php

namespace Devletes\Sidekick\Storage;

use Devletes\Sidekick\Support\TokenBudget;
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/** Long tool results are stubbed on read; calls must stay or the model learns to skip proposing. */
class LeanConversationStore extends DatabaseConversationStore
{
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
                if ($record->role === 'user') {
                    $note = $this->attachmentNote($record->attachments ?? null);

                    // Context goes last, as sent, so the replayed message matches the prompt cache.
                    $content = collect([(string) $record->content, $note, (string) ($record->context ?? '')])
                        ->filter(fn (string $part): bool => trim($part) !== '')
                        ->join("\n\n");

                    return trim($content) === '' ? [] : [new Message('user', $content)];
                }

                return $this->leanTurn($this->assistantTurnFrom($record));
            });
    }

    /**
     * @param  array<int, Message>  $messages
     * @return array<int, Message>
     */
    protected function leanTurn(array $messages): array
    {
        return array_map(fn (Message $message): Message => $message instanceof ToolResultMessage
            ? new ToolResultMessage($message->toolResults
                ->map(fn (ToolResult $result): ToolResult => ToolResult::fromArray($this->leanResult($result->toArray())))
                ->values())
            : $message, $messages);
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
     * Cuts in half-budget pages so the history start stays put across turns and keeps hitting the prompt cache.
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
        $messages = $messages->values();
        $costs = $messages->map(fn (Message $message): int => $this->costOf($message))->all();
        $page = max(1, intdiv($budget, 2));

        $starts = [0];
        $filled = 0;

        foreach ($costs as $index => $cost) {
            if ($index > 0 && $filled + $cost > $page) {
                $starts[] = $index;
                $filled = 0;
            }

            $filled += $cost;
        }

        foreach ($starts as $start) {
            if (array_sum(array_slice($costs, $start)) <= $budget) {
                return $messages->slice($start)->values();
            }
        }

        // Last page alone is over budget: keep what fits newest-first, always including the latest message.
        $kept = [];
        $spent = 0;

        foreach (array_reverse(array_keys($costs)) as $index) {
            if ($kept !== [] && $spent + $costs[$index] > $budget) {
                break;
            }

            $kept[] = $messages[$index];
            $spent += $costs[$index];
        }

        return collect(array_reverse($kept));
    }

    protected function costOf(Message $message): int
    {
        return TokenBudget::estimate((string) $message->content
            .match (true) {
                $message instanceof ToolResultMessage => json_encode($message->toolResults->all()),
                $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty() => json_encode($message->toolCalls->all()),
                default => '',
            });
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
