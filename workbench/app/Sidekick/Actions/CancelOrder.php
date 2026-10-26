<?php

namespace Workbench\App\Sidekick\Actions;

use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Workbench\App\Models\Order;

class CancelOrder extends SidekickAction
{
    public function description(): string
    {
        return 'Cancel an order that has not shipped yet and refund it in full.';
    }

    public function label(): string
    {
        return 'Preparing the cancellation';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('The order number, without the #.')->required(),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        $order = Order::query()->where('number', $payload['number'] ?? null)->first();

        if (! $order) {
            throw new InvalidArgumentException('No order has that number.');
        }

        if ($order->status !== 'paid') {
            throw new InvalidArgumentException("Order #{$order->number} is {$order->status}; only unshipped orders can be cancelled.");
        }

        return [
            'payload' => ['number' => $order->number],
            'summary' => "Cancel order #{$order->number}",
            'preview' => [
                ['label' => 'Customer', 'value' => $order->customer],
                ['label' => 'Items', 'value' => (string) $order->items],
                ['label' => 'Refund', 'value' => Order::money($order->total).' in full'],
            ],
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        $order = Order::query()->where('number', $payload['number'])->where('status', 'paid')->firstOrFail();

        $order->update(['status' => 'cancelled', 'refunded' => $order->total]);

        return 'Cancelled and refunded in full.';
    }
}
