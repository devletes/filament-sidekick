<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ChatTool;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class ChatToolBase implements ChatTool
{
    protected ?Authenticatable $user = null;

    /** Set by the registry before the tool is offered to the model. */
    public function forUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    public function needsConfirmation(): bool
    {
        return false;
    }

    /** Compact JSON reads best for the model. */
    protected function respond(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
