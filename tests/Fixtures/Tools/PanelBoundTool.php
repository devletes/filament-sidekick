<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class PanelBoundTool extends ChatToolBase
{
    public function description(): string
    {
        return 'Only for the testing panel.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }

    public function panels(): array
    {
        return ['testing'];
    }

    public function handle(Request $request): string
    {
        return $this->respond(['ok' => true]);
    }
}
