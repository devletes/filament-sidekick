<?php

namespace Devletes\Sidekick\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Lightweight nudge broadcast per user (the only stable subscription key — the panel mounts on a fresh conversation); the panel re-renders from the database. */
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
