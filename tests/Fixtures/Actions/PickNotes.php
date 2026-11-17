<?php

namespace Devletes\Sidekick\Tests\Fixtures\Actions;

use Devletes\Sidekick\Contracts\HasConfirmationForm;
use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\SidekickAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

class PickNotes extends SidekickAction implements HasConfirmationForm
{
    public static array $picked = [];

    public function description(): string
    {
        return 'Archives the notes the user ticks.';
    }

    public function confirmation(): ConfirmationMode
    {
        return ConfirmationMode::Modal;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'titles' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        return [
            'payload' => [
                'form' => ['rows' => array_map(fn (string $title): array => ['title' => $title, 'selected' => true], $payload['titles'])],
            ],
            'summary' => 'Archive '.count($payload['titles']).' notes',
            'preview' => [],
        ];
    }

    public function confirmationForm(PendingAction $action): array
    {
        return [
            Repeater::make('rows')
                ->hiddenLabel()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->schema([
                    Checkbox::make('selected')->label(fn (Get $get): string => (string) $get('title')),
                ]),
        ];
    }

    public function confirmationWidth(): Width|string
    {
        return Width::FourExtraLarge;
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        $ticked = collect($payload['form']['rows'] ?? [])->where('selected', true)->pluck('title')->all();

        if ($ticked === []) {
            throw new InvalidArgumentException('Tick at least one note.');
        }

        static::$picked = $ticked;

        return 'Archived '.count($ticked).'.';
    }
}
