<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Variants\Models\Variants;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Variant Search', category: 'inventory')]
class VariantSearchTool extends Tool
{
    // The tenant comes from the AGENT context (RunVoiceAgentToolAction::withContext),
    // never from the model. app/company arrived as LLM-supplied `apps_id`/`companies_id`
    // arguments before — untrusted, prompt-injectable input that let a caller steer the
    // search into another tenant's variants. Scope to the agent's own app + company only.
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'variant_search',
            description: 'Search product variants by name or SKU. '
                . 'Returns variant details including SKU, price, stock, and its parent product name. '
                . 'Searches only within the company bound to the agent context. '
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
                description: 'Name or SKU to search for. Partial matches are supported.',
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

        $variants = Variants::fromApp($this->app)
            ->fromCompany($this->company)
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
