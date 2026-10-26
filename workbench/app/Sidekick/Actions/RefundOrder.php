<?php

namespace Workbench\App\Sidekick\Actions;

use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Support\SidekickAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Workbench\App\Models\Order;

class RefundOrder extends SidekickAction
{
    public function description(): string
    {
        return 'Refund part or all of an order. The card shows the amount, where it goes and what is restocked before anything is sent.';
    }

    public function label(): string
    {
        return 'Preparing the refund';
    }

    /** The preview carries more than the panel dock fits. */
    public function confirmation(): ConfirmationMode
    {
        return ConfirmationMode::Modal;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('The order number, without the #.')->required(),
            'amount' => $schema->number()->description('Amount to refund. Omit to refund the rest of the order.'),
            'restock' => $schema->boolean()->description('Whether the returned items go back into stock.'),
            'reason' => $schema->string()->description('Optional note kept with the refund.'),
        ];
    }

    public function prepare(array $payload, Authenticatable $user): array
    {
        $order = Order::query()->where('number', $payload['number'] ?? null)->first();

        if (! $order) {
            throw new InvalidArgumentException('No order has that number.');
        }

        $refundable = $order->total - $order->refunded;
        $amount = (float) ($payload['amount'] ?? $refundable);

        if ($amount <= 0 || $amount > $refundable) {
            throw new InvalidArgumentException('Order #'.$order->number.' has '.Order::money($refundable).' left to refund.');
        }

        return [
            'payload' => [
                'number' => $order->number,
                'amount' => $amount,
                'restock' => (bool) ($payload['restock'] ?? false),
                'reason' => $payload['reason'] ?? null,
            ],
            'summary' => 'Refund '.Order::money($amount)." on order #{$order->number}",
            'preview' => array_values(array_filter([
                ['label' => 'Customer', 'value' => $order->customer],
                ['label' => 'Placed', 'value' => $order->placed_at->format('D, M j')],
                ['label' => 'Order total', 'value' => Order::money($order->total)],
                ['label' => 'Refund', 'value' => Order::money($amount)],
                ['label' => 'Refund to', 'value' => 'Original payment method'],
                ['label' => 'Restock items', 'value' => ($payload['restock'] ?? false) ? 'Yes' : 'No'],
                filled($payload['reason'] ?? null) ? ['label' => 'Reason', 'value' => $payload['reason']] : null,
            ])),
        ];
    }

    public function execute(array $payload, Authenticatable $user): string
    {
        $order = Order::query()->where('number', $payload['number'])->firstOrFail();

        $order->increment('refunded', $payload['amount']);

        if ($order->refresh()->refunded >= $order->total) {
            $order->update(['status' => 'refunded']);
        }

        return 'Refunded '.Order::money($payload['amount']).'.';
    }
}
