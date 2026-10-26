<?php

namespace Workbench\App\Sidekick\Tools;

use Devletes\Sidekick\Support\ChatToolBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Workbench\App\Models\Product;

class StockLevels extends ChatToolBase
{
    public function description(): string
    {
        return 'Products at or below their reorder point, with stock on hand.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function label(): string
    {
        return 'Checking stock levels';
    }

    public function handle(Request $request): string
    {
        $low = Product::query()
            ->whereColumn('stock', '<=', 'reorder_at')
            ->orderBy('stock')
            ->get(['name', 'sku', 'stock', 'reorder_at']);

        return $this->respond(['low_stock' => $low]);
    }
}
