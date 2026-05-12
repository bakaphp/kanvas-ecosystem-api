<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class InventorySearchTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'inventory_search',
            description: 'Search for products in the inventory by name and check their availability and stock levels.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'product_name',
                type: ToolsPropertyType::STRING,
                description: 'The product name or keyword to search for in the inventory.',
                required: true,
            ),
            new ToolProperty(
                name: 'companies_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the company to search within.',
                required: true,
            ),
            new ToolProperty(
                name: 'apps_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the app context.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $product_name, int $companies_id, int $apps_id): array
    {
        $app = Apps::getById($apps_id);
        $company = Companies::getById($companies_id);
        $allowCrossCompany = (bool) $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        $builder = Products::fromApp($app)
            ->notDeleted()
            ->where('name', 'like', '%' . $product_name . '%')
            ->with('variants')
            ->limit(10);

        if (! $allowCrossCompany) {
            $builder->fromCompany($company);
        }

        $products = $builder->get();

        if ($products->isEmpty()) {
            return ['message' => "No products found matching '{$product_name}'."];
        }

        return $products->map(function (Products $product) {
            $variants = $product->variants;
            $totalStock = $variants->sum(fn ($variant) => $variant->getTotalQuantity());
            $isAvailable = $totalStock > 0 && $product->is_published;

            return [
                'id' => $product->getId(),
                'name' => $product->name,
                'slug' => $product->slug,
                'is_published' => (bool) $product->is_published,
                'is_available' => $isAvailable,
                'total_stock' => $totalStock,
                'variants' => $variants->map(function ($variant) {
                    try {
                        $price = $variant->getPriceInfoFromDefaultChannel()->price ?? null;
                    } catch (Throwable) {
                        $price = null;
                    }

                    return [
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'stock' => $variant->getTotalQuantity(),
                        'price' => $price,
                    ];
                })->toArray(),
            ];
        })->toArray();
    }
}
