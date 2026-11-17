<?php

namespace Workbench\App\Sidekick\Actions;

use Devletes\Sidekick\Contracts\HasConfirmationForm;
use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\SidekickAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Workbench\App\Models\Order;

/** Several paid orders shipped at once, each ticked on the card. */
class ShipOrders extends SidekickAction implements HasConfirmationForm
{
    public function description(): string
    {
        return 'Mark several paid orders as shipped at once. The user ticks which ones on the card.';
    }

    public function label(): string
    {
        return 'Preparing the shipments';
    }

    public function confirmation(): ConfirmationMode
    {
        return ConfirmationMode::Modal;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'numbers' => $schema->array()->items($schema->integer())->description('The order numbers, without the #.')->required(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        $orders = Order::query()->whereIn('number', (array) ($payload['numbers'] ?? []))->where('status', 'paid')->orderByDesc('number')->get();

        if ($orders->isEmpty()) {
            throw new InvalidArgumentException('None of those orders is paid and waiting to ship.');
        }

        return [
            'payload' => [
                'numbers' => $orders->pluck('number')->all(),
                'form' => ['orders' => $orders->map(fn (Order $order): array => self::row($order))->all()],
            ],
            'summary' => 'Ship '.$orders->count().' orders',
            'preview' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function row(Order $order): array
    {
        return [
            'number' => $order->number,
            'selected' => true,
            'order' => '#'.$order->number,
            'customer' => $order->customer,
            'items' => (string) $order->items,
            'total' => Order::money($order->total),
        ];
    }

    public function confirmationForm(PendingAction $action): array
    {
        return [
            Repeater::make('orders')
                ->hiddenLabel()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->table([
                    TableColumn::make('Ship')->hiddenHeaderLabel()->width('2.5rem'),
                    TableColumn::make('Order')->width('6rem'),
                    TableColumn::make('Customer'),
                    TableColumn::make('Items')->width('5rem'),
                    TableColumn::make('Total')->width('7rem'),
                ])
                ->schema([
                    Checkbox::make('selected')->hiddenLabel()->live(),
                    Text::make(fn (Get $get): string => $get('order'))->weight('medium'),
                    Text::make(fn (Get $get): string => $get('customer')),
                    Text::make(fn (Get $get): string => $get('items'))->color('gray'),
                    Text::make(fn (Get $get): string => $get('total')),
                ]),
            Text::make(fn (Get $get): string => collect($get('orders'))->where('selected', true)->count().' of '.count($get('orders') ?? []).' selected')->color('gray'),
        ];
    }

    public function confirmationWidth(): Width|string
    {
        return Width::ThreeExtraLarge;
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        $numbers = collect($payload['form']['orders'] ?? [])
            ->where('selected', true)
            ->pluck('number')
            ->intersect($payload['numbers'] ?? [])
            ->values();

        if ($numbers->isEmpty()) {
            throw ValidationException::withMessages(['orders' => 'Tick at least one order to ship.']);
        }

        $shipped = Order::query()->whereIn('number', $numbers)->where('status', 'paid')->update(['status' => 'shipped', 'shipped_at' => now()]);

        return 'Shipped '.$shipped.' orders: #'.$numbers->join(', #').'.';
    }
}
