<?php

namespace Devletes\Sidekick\Tests\Fixtures\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class EchoTool extends ChatToolBase
{
    public function description(): string
    {
        return 'Echoes the input back.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->respond(['echo' => $request['text']]);
    }
}
