<?php

namespace App\Mcp\Inventory;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Kanvas\Inventory\Variants\Models\Variants;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpTool;
use Psr\Log\LoggerInterface;

class GetProductsTool
{
    public function __construct(private LoggerInterface $logger) {}

    #[McpResource(uri: 'laravel://config/app.name', mimeType: 'text/plain')]
    public function getAppName(): string
    {
        return Config::get('app.name', 'Laravel');
    }

    #[McpTool]
    public function searchProducts(string $name): JsonResponse
    {
        $this->logger->info('Searching products via MCP');

        $products = Variants::query()
            ->with('variantWarehouses:quantity,price')
            ->where('name', 'like', "%{$name}%")
            ->where('is_deleted', 0)
            ->orWhere('sku', 'like', "%{$name}%")
            ->limit(10)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'description']);

        if ($products->isEmpty()) {
            $this->logger->info('No products found');
            return response()->json(['results' => []]);
        }

        $products = $products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'quantity' => $product->variant_warehouses['quantity'],
                'price' => $product->variant_warehouses['price'],
            ];
        });

        return response()->json(['results' => $products]);
    }
}