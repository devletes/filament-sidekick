<?php

namespace Devletes\Sidekick\Tests\Fixtures\Actions;

use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

class CreateNote extends SidekickAction
{
    public static array $created = [];

    public function description(): string
    {
        return 'Creates a note.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->required(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        $body = trim((string) ($payload['body'] ?? ''));

        if ($body === '') {
            throw new InvalidArgumentException('A body is required.');
        }

        return [
            'payload' => ['body' => $body],
            'summary' => 'Create a note',
            'preview' => [['label' => 'Body', 'value' => $body]],
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        static::$created[] = $payload['body'];

        return 'Note created.';
    }
}
