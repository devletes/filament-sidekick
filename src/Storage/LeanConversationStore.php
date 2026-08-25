<?php

namespace Devletes\Sidekick\Storage;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Storage\DatabaseConversationStore;

/** Rehydrates history without past tool calls/results (the model re-calls tools for fresh data); writes keep the full record. */
class LeanConversationStore extends DatabaseConversationStore
{
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
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
                }

                if (trim($content) === '') {
                    return []; // tool-only turns carry no reusable text
                }

                return [
                    $record->role === 'user'
                        ? new Message('user', $content)
                        : new AssistantMessage($content),
                ];
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
