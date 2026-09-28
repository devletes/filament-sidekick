<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools;

use Devletes\Sidekick\Contracts\ProvidesTurnContext;
use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class StatusTool extends ChatToolBase implements ProvidesTurnContext
{
    public static string $now = 'Step 3 of 9: Positions.';

    public function description(): string
    {
        return 'Reads where setup stands.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function turnContext(): ?string
    {
        return static::$now;
    }

    public function handle(Request $request): string
    {
        return $this->respond(['ok' => true]);
    }
}
