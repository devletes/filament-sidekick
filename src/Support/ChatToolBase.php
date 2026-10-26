<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ChatTool;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

abstract class ChatToolBase implements ChatTool
{
    protected ?Authenticatable $user = null;

    public function forUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function authorize(Authenticatable $user): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Using: '.Str::headline(class_basename(static::class));
    }

    public function instructions(): ?string
    {
        return null;
    }

    public function panels(): array
    {
        return ['*'];
    }

    /** Resources/models this tool reads, so deleting one is reported as a problem. */
    public function dependsOn(): array
    {
        return [];
    }

    protected function respond(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
