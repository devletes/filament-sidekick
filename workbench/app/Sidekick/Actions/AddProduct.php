<?php

namespace Workbench\App\Sidekick\Actions;

use Devletes\Sidekick\Contracts\HasConfirmationForm;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\SidekickAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Product;

/** A new product, its details shown as a form the user corrects before confirming. */
class AddProduct extends SidekickAction implements HasConfirmationForm
{
    public function description(): string
    {
        return 'Add a product to the catalogue. The user can correct any detail on the card before confirming.';
    }

    public function label(): string
    {
        return 'Preparing the product';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The product name.')->required(),
            'sku' => $schema->string()->description('The stock keeping unit, in capitals.')->required(),
            'stock' => $schema->integer()->description('How many are in stock.')->required(),
            'reorder_at' => $schema->integer()->description('The stock level that triggers a reorder.'),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        return [
            'payload' => [
                'form' => [
                    'name' => (string) ($payload['name'] ?? ''),
                    'sku' => strtoupper((string) ($payload['sku'] ?? '')),
                    'stock' => (int) ($payload['stock'] ?? 0),
                    'reorder_at' => (int) ($payload['reorder_at'] ?? 10),
                ],
            ],
            'summary' => 'Add a product',
            'preview' => [],
        ];
    }

    public function confirmationForm(PendingAction $action): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('sku')->label('SKU')->required()->maxLength(32),
            Grid::make(2)->schema([
                TextInput::make('stock')->integer()->minValue(0)->required(),
                TextInput::make('reorder_at')->label('Reorder at')->integer()->minValue(0)->required(),
            ]),
        ];
    }

    public function confirmationWidth(): Width|string
    {
        return Width::Medium;
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        $form = $payload['form'];

        if (Product::query()->where('sku', $form['sku'])->exists()) {
            throw ValidationException::withMessages(['sku' => "A product with SKU {$form['sku']} already exists."]);
        }

        $product = Product::query()->create([
            'name' => $form['name'],
            'sku' => $form['sku'],
            'stock' => (int) $form['stock'],
            'reorder_at' => (int) $form['reorder_at'],
        ]);

        return "Added {$product->name} ({$product->sku}) with {$product->stock} in stock.";
    }
}
