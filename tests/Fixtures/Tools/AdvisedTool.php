<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class AdvisedTool extends ChatToolBase
{
    public function description(): string
    {
        return 'Looks up employees.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }

    public function instructions(): ?string
    {
        return 'Always confirm the employee ID before proposing actions about a person.';
    }

    public function handle(Request $request): string
    {
        return $this->respond(['ok' => true]);
    }
}
