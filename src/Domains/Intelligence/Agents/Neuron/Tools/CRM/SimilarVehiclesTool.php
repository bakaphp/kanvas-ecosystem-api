<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Inventory\Variants\Models\Variants;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Similar Vehicles')]
class SimilarVehiclesTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'find_similar_vehicles',
            description: 'Find similar vehicles in inventory based on the lead\'s vehicle of interest (make and model).',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
            new ToolProperty(
                name: 'make',
                type: ToolsPropertyType::STRING,
                description: 'The vehicle make to search for (e.g. Toyota, Ford).',
                required: true,
            ),
            new ToolProperty(
                name: 'model',
                type: ToolsPropertyType::STRING,
                description: 'The vehicle model to search for (e.g. Camry, F-150).',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id, string $make, string $model): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $relatedVariant = Variants::searchByMultipleAttributes(
            app: $lead->app,
            attributes: [
                ['name' => 'make', 'value' => $make],
                ['name' => 'model', 'value' => $model],
            ],
            locale: 'en',
            user: null,
            company: $lead->company,
        )->select('products_variants.uuid', 'products_variants.name')->limit(10)->get();

        return $relatedVariant->toArray();
    }
}
