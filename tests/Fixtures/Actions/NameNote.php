<?php

namespace Devletes\Sidekick\Tests\Fixtures\Actions;

use Devletes\Sidekick\Contracts\HasConfirmationForm;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\SidekickAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;

/** An editable card: the note's title, filled in, which the user may correct before confirming. */
class NameNote extends SidekickAction implements HasConfirmationForm
{
    public static array $saved = [];

    public function description(): string
    {
        return 'Creates a note with the title given.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        return [
            'payload' => ['form' => ['title' => $payload['title']]],
            'summary' => 'Create a note',
            'preview' => [],
        ];
    }

    public function confirmationForm(PendingAction $action): array
    {
        return [TextInput::make('title')->required()];
    }

    public function confirmationWidth(): Width|string
    {
        return Width::Large;
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        $title = (string) ($payload['form']['title'] ?? '');

        if ($title === 'Taken') {
            throw ValidationException::withMessages(['title' => 'A note called Taken already exists.']);
        }

        if ($title === 'Closed') {
            throw ValidationException::withMessages(['notebook' => 'That notebook is closed.']);
        }

        static::$saved[] = $title;

        return 'Created '.$title.'.';
    }
}
