<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Sales;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Variants\Models\Variants;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Resolves a product name (or partial SKU) to the synced variants it could be, each with its SKU —
 * how the agent turns "the Kraken Elite 360" into `RL-KP336` before creating a sample order. Returns
 * candidates to disambiguate rather than a single guess.
 */
#[AgentTool(name: 'Find Product', category: 'commerce')]
class FindProductTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_product',
            description: 'Finds products/variants matching a name or partial SKU, each with its exact SKU. Use this '
                . 'to resolve a product the user named ("the Kraken Elite") into a SKU before create_sample_order. '
                . 'Returns candidates — confirm the right one if there is more than one.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The product name or partial SKU to look up.',
                required: true,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max candidates to return. Defaults to 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?int $limit = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $limit = max(1, min(25, $limit ?? 10));
        $term = trim($query);

        $variants = Variants::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where(function ($q) use ($term): void {
                $q->where('name', 'like', '%' . $term . '%')
                    ->orWhere('sku', 'like', '%' . $term . '%');
            })
            ->limit($limit)
            ->get();

        return [
            'query' => $query,
            'count' => $variants->count(),
            'products' => $variants->map(fn (Variants $variant): array => [
                'sku' => $variant->sku,
                'variant' => $variant->name,
                'product' => $variant->product?->name,
            ])->all(),
        ];
    }
}
