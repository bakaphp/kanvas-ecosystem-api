<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class VariantSearchTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'variant_search',
            description: 'Search product variants by name or SKU. Returns variant details including SKU, price, stock, and its parent product name. Use this when the user asks about a specific SKU or variant name.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'keyword',
                type: ToolsPropertyType::STRING,
                description: 'Name or SKU to search for. Partial matches are supported.',
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

    public function __invoke(string $keyword, int $companies_id, int $apps_id): array
    {
        if ($keyword === '') {
            return ['message' => 'Please provide a keyword (name or SKU) to search for variants.'];
        }

        $app = Apps::getById($apps_id);
        $company = Companies::getById($companies_id);

        $variants = Variants::fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                    ->orWhere('sku', 'like', '%' . $keyword . '%');
            })
            ->with('product')
            ->limit(20)
            ->get();

        if ($variants->isEmpty()) {
            return ['message' => "No variants found matching '{$keyword}'."];
        }

        return $variants->map(fn (Variants $variant) => [
            'id' => $variant->getId(),
            'name' => $variant->name,
            'sku' => $variant->sku,
            'product' => $variant->product?->name,
            'is_published' => (bool) $variant->is_published,
            'stock' => $variant->getTotalQuantity(),
        ])->toArray();
    }
}
