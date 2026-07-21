<?php

namespace Devletes\Sidekick\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PendingAction extends Model
{
    use HasUuids;

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $table = 'sidekick_pending_actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'preview' => 'array',
            'upload' => 'array',
            'expires_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function isConfirmable(): bool
    {
        return $this->status === self::STATUS_PROPOSED
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The confirm card should render a file field for this action. */
    public function acceptsUpload(): bool
    {
        return is_array($this->upload) && $this->upload !== [];
    }

    /** Confirm must be blocked until at least one file is attached. */
    public function requiresUpload(): bool
    {
        return $this->acceptsUpload()
            && (bool) ($this->upload['required'] ?? false)
            && empty($this->payload['attachment_ids'] ?? null);
    }
}
