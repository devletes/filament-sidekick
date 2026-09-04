<?php

namespace Devletes\Sidekick\Tests\Fixtures\Actions;

use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Stands in for an action whose model was deleted: it must stop being both proposable and confirmable. */
class BrokenAction extends SidekickAction
{
    public const MISSING = 'Devletes\Sidekick\Tests\Fixtures\DeletedModel';

    public function description(): string
    {
        return 'Writes to a model that is no longer there.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function dependsOn(): array
    {
        return [self::MISSING];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        return ['payload' => $payload, 'summary' => 'Never reached', 'preview' => []];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        return 'Never reached.';
    }
}
