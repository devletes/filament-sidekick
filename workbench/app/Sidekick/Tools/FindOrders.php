<?php

namespace Workbench\App\Sidekick\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Workbench\App\Models\Order;

class FindOrders extends ChatToolBase
{
    public function description(): string
    {
        return 'Orders matching a number, customer name or status, newest first.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('An order number or part of a customer name.'),
            'status' => $schema->string()->enum(['paid', 'shipped', 'refunded', 'cancelled']),
        ];
    }

    public function label(): string
    {
        return 'Looking up orders';
    }

    public function handle(Request $request): string
    {
        $orders = Order::query()
            ->when(filled($request['search'] ?? null), fn ($query) => $query
                ->where('number', $request['search'])
                ->orWhere('customer', 'like', '%'.$request['search'].'%'))
            ->when(filled($request['status'] ?? null), fn ($query) => $query->where('status', $request['status']))
            ->latest('placed_at')
            ->limit(20)
            ->get()
            ->map(fn (Order $order): array => [
                'number' => $order->number,
                'customer' => $order->customer,
                'items' => $order->items,
                'total' => Order::money($order->total),
                'status' => $order->status,
                'placed' => $order->placed_at->toDateString(),
            ]);

        return $this->respond(['orders' => $orders]);
    }
}
