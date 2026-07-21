<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Models\Attachment;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persists chat uploads onto a host-configured disk and hands back Attachment
 * rows. Validation (type, size, count) happens here so every entry path —
 * composer, confirm card — enforces the same host rules. File contents are
 * never read into the conversation; callers only ever pass metadata onward.
 */
class AttachmentStore
{
    public function enabled(): bool
    {
        return (bool) config('sidekick.attachments.enabled', false);
    }

    public function disk(): string
    {
        return config('sidekick.attachments.disk') ?: config('filesystems.default');
    }

    /** @return string[] Accepted mime patterns (exact or `type/*`). */
    public function accept(): array
    {
        return array_values((array) config('sidekick.attachments.accept', []));
    }

    /** Comma-joined accept list for the file input's `accept` attribute. */
    public function acceptAttribute(): string
    {
        return implode(',', $this->accept());
    }

    public function maxFiles(): int
    {
        return max(1, (int) config('sidekick.attachments.max_files', 4));
    }

    /** @throws InvalidArgumentException with a user-readable message. */
    public function store(UploadedFile $file, Authenticatable $user, ?string $conversationId): Attachment
    {
        $this->validate($file);

        // Capture EVERYTHING before storeAs(): when source and target share a
        // disk, Livewire's TemporaryUploadedFile::storeAs MOVES the temp file,
        // so any later read (getSize/getMimeType/guessExtension) hits a
        // deleted path and throws. Livewire's test mode reads these from the
        // surviving .json manifest instead, so a post-store read passes every
        // test and only crashes in production — do not reorder.
        $originalName = $file->getClientOriginalName() ?: 'file';
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $size = (int) $file->getSize();
        $basename = $this->safeFileName($originalName, $this->resolveExtension($file));

        // A uuid FOLDER with a sanitized original basename inside: unique on
        // disk, while anything consuming the path (e.g. a host copying the
        // file into a media collection) sees the human filename.
        $directory = trim((string) config('sidekick.attachments.directory', 'sidekick-attachments'), '/')
            .'/'.now()->format('Y/m')
            .'/'.Str::uuid7()->toString();

        $stored = $file->storeAs($directory, $basename, ['disk' => $this->disk()]);

        if ($stored === false) {
            throw new InvalidArgumentException('The file could not be stored — try again.');
        }

        $attachment = new Attachment([
            'conversation_id' => $conversationId,
            'user_id' => $user->getAuthIdentifier(),
            'name' => Str::limit($originalName, 150, ''),
            'disk' => $this->disk(),
            'path' => $stored,
            'mime' => $mime,
            'size' => $size,
            'status' => Attachment::STATUS_STAGED,
        ]);

        $attachment->save();

        return $attachment;
    }

    /** @throws InvalidArgumentException */
    public function validate(UploadedFile $file): void
    {
        if (! $this->enabled()) {
            throw new InvalidArgumentException('Attachments are disabled.');
        }

        $maxKb = (int) config('sidekick.attachments.max_size', 12288);

        if ($file->getSize() > $maxKb * 1024) {
            $limit = $maxKb >= 1024 ? round($maxKb / 1024, 1).' MB' : $maxKb.' KB';

            throw new InvalidArgumentException("\"{$file->getClientOriginalName()}\" is too large — the limit is {$limit}.");
        }

        // Server-detected mime (content sniff), not the client-declared one.
        if (! $this->mimeAccepted((string) $file->getMimeType())) {
            throw new InvalidArgumentException("\"{$file->getClientOriginalName()}\" is not an accepted file type.");
        }
    }

    public function mimeAccepted(string $mime): bool
    {
        $accept = $this->accept();

        if ($accept === []) {
            return true;
        }

        foreach ($accept as $pattern) {
            if (str_ends_with($pattern, '/*')) {
                if (str_starts_with($mime, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif (strcasecmp($pattern, $mime) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ownership-scoped metadata for a set of attachment ids, in id order.
     * Unknown/foreign ids are silently dropped.
     *
     * @return array<int, array{id: string, name: string, mime: string, size: int}>
     */
    public function metadataFor(array $ids, Authenticatable $user): array
    {
        if ($ids === []) {
            return [];
        }

        return Attachment::query()
            ->forUser($user)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Attachment $attachment) => array_search($attachment->id, $ids, true))
            ->map(fn (Attachment $attachment): array => $attachment->toMetadata())
            ->values()
            ->all();
    }

    protected function resolveExtension(UploadedFile $file): string
    {
        // Content-derived first; the client name is display-only.
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();

        return Str::limit(preg_replace('/[^a-z0-9]/i', '', (string) $extension) ?? '', 10, '');
    }

    protected function safeFileName(?string $original, string $extension): string
    {
        $stem = pathinfo((string) $original, PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[^\pL\pN \-_\.]/u', '', $stem));
        $stem = $stem !== '' ? Str::limit($stem, 60, '') : 'file';

        return $stem.($extension !== '' ? ".{$extension}" : '');
    }
}
