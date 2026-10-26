<?php

namespace Workbench\App\Sidekick\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Workbench\App\Models\Order;

class SalesSummary extends ChatToolBase
{
    public function description(): string
    {
        return 'Revenue, order count and orders by status for a date range, with the same figures for the range before it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start of the range, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End of the range, YYYY-MM-DD.')->required(),
        ];
    }

    public function label(): string
    {
        return 'Checking sales figures';
    }

    public function instructions(): ?string
    {
        return 'Always quote revenue and order counts from SalesSummary rather than adding up orders yourself.';
    }

    public function handle(Request $request): string
    {
        $orders = Order::query()
            ->whereDate('placed_at', '>=', $request['from'])
            ->whereDate('placed_at', '<=', $request['to'])
            ->get();

        return $this->respond([
            'revenue' => Order::money($orders->sum('total') - $orders->sum('refunded')),
            'orders' => $orders->count(),
            'by_status' => $orders->countBy('status'),
        ]);
    }
}
