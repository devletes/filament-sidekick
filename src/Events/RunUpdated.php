<?php

namespace Devletes\Sidekick\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Lightweight nudge — the panel re-renders from the database on receipt, so
 * the payload stays minimal and version-proof.
 *
 * Broadcast on a per-USER channel (not per-conversation): Livewire registers
 * echo listeners when the component mounts, and the panel mounts on a fresh
 * conversation — the user id is the only stable subscription key.
 */
class RunUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int|string $userId,
        public string $conversationId,
        public string $runId,
        public string $status,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('sidekick.user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'sidekick.run.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'run_id' => $this->runId,
            'status' => $this->status,
        ];
    }
}
