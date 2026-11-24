<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\Concerns\RespondsWithNoInventoryMatches;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Variants\Services\VariantSearchService;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Variant Search', category: 'inventory', readOnly: true)]
class VariantSearchTool extends Tool
{
    // The tenant comes from the agent context, never from LLM-supplied ids, which are prompt-injectable.
    use HasKanvasContext;
    use RespondsWithNoInventoryMatches;

    protected string $name = 'variant_search';

    protected ?string $description = 'Search product variants through the configured search engine by name, SKU, EAN, or barcode. '
        . 'Returns variant details including SKU, stock, and its parent product name. '
        . 'Searches only within the company bound to the agent context. '
        . 'Use this when the user asks about a specific SKU or variant name.';

    public function __construct(private readonly VariantSearchService $searchService = new VariantSearchService())
    {
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
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('variant search');
        }

        if ($keyword === '') {
            return ['message' => 'Please provide a keyword (name or SKU) to search for variants.'];
        }

        try {
            $variants = $this->searchService->search($this->app, $this->company, $keyword);
        } catch (Throwable $e) {
            // The engine's message names index fields and filters — log it, never speak it.
            report($e);

            return $this->searchUnavailableResponse('variant');
        }

        if ($variants === []) {
            return ['message' => "No variants found matching '{$keyword}'."];
        }

        return $variants;
    }
}
