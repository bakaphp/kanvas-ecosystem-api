<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\VariantSearchService;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Variant Search', category: 'inventory')]
class VariantSearchTool extends Tool
{
    use HasKanvasContext;

    public function __construct(private readonly VariantSearchService $searchService = new VariantSearchService())
    {
        parent::__construct(
            name: 'variant_search',
            description: 'Search product variants through the configured search engine by name, SKU, EAN, or barcode. '
                . 'Returns variant details including SKU, stock, and its parent product name. '
                . 'Use this when the user asks about a specific SKU or variant name.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'keyword',
                type: ToolsPropertyType::STRING,
                description: 'Variant name, SKU, EAN, barcode, or related search terms.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $keyword): array
    {
        if ($keyword === '') {
            return ['message' => 'Please provide a keyword (name or SKU) to search for variants.'];
        }

        $variants = $this->searchService->search($this->app, $this->company, $keyword);

        if ($variants === []) {
            return ['message' => "No variants found matching '{$keyword}'."];
        }

        return $variants;
    }
}
