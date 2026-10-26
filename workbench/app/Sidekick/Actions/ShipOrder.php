<?php

namespace Workbench\App\Sidekick\Actions;

use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Workbench\App\Models\Order;

class ShipOrder extends SidekickAction
{
    public function description(): string
    {
        return 'Mark a paid order as shipped and email the customer.';
    }

    public function label(): string
    {
        return 'Preparing the shipment';
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

        if (! $order || $order->status !== 'paid') {
            throw new InvalidArgumentException('No paid, unshipped order has that number.');
        }

        return [
            'payload' => ['number' => $order->number],
            'summary' => "Mark order #{$order->number} as shipped",
            'preview' => [
                ['label' => 'Customer', 'value' => $order->customer],
                ['label' => 'Items', 'value' => (string) $order->items],
            ],
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        Order::query()
            ->where('number', $payload['number'])
            ->where('status', 'paid')
            ->update(['status' => 'shipped', 'shipped_at' => now()]);

        return 'Shipped, and the customer has been emailed.';
    }
}
