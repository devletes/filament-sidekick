<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class SecretTool extends ChatToolBase
{
    public function description(): string
    {
        return 'Admin-only.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }

    public function authorize(Authenticatable $user): bool
    {
        return false;
    }

    public function instructions(): ?string
    {
        return 'Secret guidance that must never leak.';
    }

    public function handle(Request $request): string
    {
        return $this->respond(['ok' => true]);
    }
}
