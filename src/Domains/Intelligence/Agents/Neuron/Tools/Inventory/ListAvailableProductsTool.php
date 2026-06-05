<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List Available Products')]
class ListAvailableProductsTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'list_available_products',
            description: 'List products from the inventory filtered by published status and stock availability. '
                . 'Use is_published=true for published products, is_published=false for unpublished/draft products. '
                . 'Use only_in_stock=true to filter only products with stock available.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'companies_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the company to list products from.',
                required: true,
            ),
            new ToolProperty(
                name: 'apps_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the app context.',
                required: true,
            ),
            new ToolProperty(
                name: 'is_published',
                type: ToolsPropertyType::BOOLEAN,
                description: 'Filter by published status. true = published, false = unpublished/draft. Defaults to true.',
                required: false,
            ),
            new ToolProperty(
                name: 'only_in_stock',
                type: ToolsPropertyType::BOOLEAN,
                description: 'When true, only returns products with stock > 0. Defaults to false.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: ToolsPropertyType::INTEGER,
                description: 'Maximum number of products to return. Defaults to 20, max 50.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $companies_id,
        int $apps_id,
        ?bool $is_published = null,
        ?bool $only_in_stock = null,
        ?int $limit = null
    ): array {
        $app = Apps::getById($apps_id);
        $company = Companies::getById($companies_id);
        $is_published = $is_published ?? true;
        $only_in_stock = $only_in_stock ?? false;
        $limit = min($limit ?? 20, 50);
        $allowCrossCompany = (bool) $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        $builder = Products::fromApp($app)
            ->notDeleted()
            ->where('is_published', $is_published ? 1 : 0)
            ->with('variants')
            ->limit($limit);

        if (! $allowCrossCompany) {
            $builder->fromCompany($company);
        }

        $products = $builder->get();
        $label = $is_published ? 'published' : 'unpublished';

        if ($products->isEmpty()) {
            return ['message' => "No {$label} products found in the inventory."];
        }

        $results = $products->map(function (Products $product) {
            $variants = $product->variants;
            $totalStock = $variants->sum(fn ($variant) => $variant->getTotalQuantity());

            return [
                'id' => $product->getId(),
                'name' => $product->name,
                'slug' => $product->slug,
                'is_published' => (bool) $product->is_published,
                'total_stock' => $totalStock,
                'variants' => $variants->map(fn ($variant) => [
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'stock' => $variant->getTotalQuantity(),
                ])->toArray(),
            ];
        });

        if ($only_in_stock) {
            $results = $results->filter(fn ($product) => $product['total_stock'] > 0);

            if ($results->isEmpty()) {
                return ['message' => "No {$label} products with stock found."];
            }
        }

        return $results->values()->toArray();
    }
}
