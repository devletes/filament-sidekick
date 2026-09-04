<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/** Declares a dependency that does not exist — stands in for a tool whose resource was deleted. */
class BrokenTool extends ChatToolBase
{
    public const MISSING = 'Devletes\Sidekick\Tests\Fixtures\DeletedResource';

    public function description(): string
    {
        return 'Reads a resource that is no longer there.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function dependsOn(): array
    {
        return [self::MISSING];
    }

    public function handle(Request $request): string
    {
        return $this->respond([]);
    }
}
