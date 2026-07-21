<?php

namespace Devletes\Sidekick\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A file the user attached in the chat panel. Files live TEMPORARILY on a
 * host-configured disk and are referenced by id everywhere; only
 * name/mime/size metadata is ever surfaced to the model. The whole area is
 * swept by sidekick:prune-attachments — a confirmed action copies the file
 * into the host's own storage, chat history keeps only the metadata, and an
 * unconsumed file is simply irrelevant after the retention window.
 */
class Attachment extends Model
{
    use HasUuids;

    /** Uploaded from the composer/card but not yet part of a sent message. */
    public const STATUS_STAGED = 'staged';

    /** Sent with a message — part of the conversation record. */
    public const STATUS_SENT = 'sent';

    protected $guarded = [];

    public function getTable(): string
    {
        return config('sidekick.tables.attachments', 'sidekick_attachments');
    }

    /** Every id → row lookup must run through this: ids are client/model input. */
    public function scopeForUser(Builder $query, mixed $user): Builder
    {
        return $query->where('user_id', $user->getAuthIdentifier());
    }

    /** Metadata shape shared by run payloads, message rows, and the panel chips. */
    public function toMetadata(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => (int) $this->size,
        ];
    }

    public static function formatBytes(int $size): string
    {
        return $size >= 1048576
            ? round($size / 1048576, 1).' MB'
            : max(1, (int) round($size / 1024)).' KB';
    }

    public function humanSize(): string
    {
        return static::formatBytes((int) $this->size);
    }

    public function deleteWithFile(): void
    {
        rescue(function (): void {
            $storage = Storage::disk($this->disk);
            $storage->delete($this->path);

            // Each upload gets its own uuid folder — remove it once empty so
            // pruning doesn't leave directory debris. Guarded on emptiness in
            // case a host configured a flat directory layout.
            $directory = dirname($this->path);

            if ($directory !== '.'
                && $storage->files($directory) === []
                && $storage->directories($directory) === []) {
                $storage->deleteDirectory($directory);
            }
        }, report: false);

        $this->delete();
    }
}
