<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools\Nested;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/** Sits a level below the discovery root — only a recursive scan finds it. */
class NestedTool extends ChatToolBase
{
    public function description(): string
    {
        return 'Lives in a sub-namespace.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return $this->respond(['nested' => true]);
    }
}
