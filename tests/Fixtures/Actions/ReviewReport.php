<?php

namespace Devletes\Sidekick\Tests\Fixtures\Actions;

use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ReviewReport extends SidekickAction
{
    public static array $approved = [];

    public function description(): string
    {
        return 'Approves a report with a long preview.';
    }

    public function confirmation(): ConfirmationMode
    {
        return ConfirmationMode::Modal;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        return [
            'payload' => ['title' => $payload['title']],
            'summary' => 'Approve the quarterly report',
            'preview' => [['label' => 'Title', 'value' => (string) $payload['title']]],
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        static::$approved[] = $payload['title'];

        return 'Approved.';
    }
}
