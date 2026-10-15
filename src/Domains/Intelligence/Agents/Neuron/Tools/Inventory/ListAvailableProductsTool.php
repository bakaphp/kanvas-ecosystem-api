<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Kanvas\Souk\Services\StorefrontProductUrlService;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List Available Products', category: 'inventory')]
class ListAvailableProductsTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'list_available_products';

    protected ?string $description = 'List products from the inventory filtered by published status and stock availability. '
        . 'Use is_published=true for published products, is_published=false for unpublished/draft products. '
        . 'Use only_in_stock=true to filter only products with stock available.';

    #[Override]
    protected function properties(): array
    {
        return [
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

    public function __invoke(?bool $is_published = null, ?bool $only_in_stock = null, ?int $limit = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('product listing');
        }

        $is_published ??= true;
        $only_in_stock ??= false;
        $limit = min($limit ?? 20, 50);
        $allowCrossCompany = (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        $builder = Products::fromApp($this->app)
            ->notDeleted()
            ->where('is_published', $is_published ? 1 : 0)
            ->with('variants')
            ->limit($limit);

        if (! $allowCrossCompany) {
            $builder->fromCompany($this->company);
        }

        if ($only_in_stock) {
            $builder->inStock();
        }

        $products = $builder->get();
        $label = ($is_published ? 'published' : 'unpublished') . ($only_in_stock ? ' products with stock' : ' products');

        if ($products->isEmpty()) {
            return ['message' => "No {$label} found in the inventory."];
        }

        $storefronts = [];

        $results = $products->map(function (Products $product) use (&$storefronts) {
            $storefront = $storefronts[$product->companies_id] ??= StorefrontProductUrlService::forProduct($product);
            $variants = $product->variants;
            $totalStock = $variants->sum(fn ($variant) => $variant->getTotalQuantity());

            return [
                'id' => $product->getId(),
                'name' => $product->name,
                'slug' => $product->slug,
                'url' => $storefront->productUrl($product),
                'is_published' => (bool) $product->is_published,
                'total_stock' => $totalStock,
                'variants' => $variants->map(fn ($variant) => [
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'stock' => $variant->getTotalQuantity(),
                ])->toArray(),
            ];
        });

        return $results->values()->toArray();
    }
}
