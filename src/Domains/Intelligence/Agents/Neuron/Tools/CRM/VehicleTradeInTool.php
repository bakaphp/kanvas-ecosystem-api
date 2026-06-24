<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Vehicle Trade In')]
class VehicleTradeInTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'get_vehicle_trade_in',
            description: 'Get vehicle trade-in information from the lead, including mileage, colors, year, make, model, trim, and VIN.',
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
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $vehicleTradeIn = $lead->get(LeadCustomFieldEnum::TRADE_IN->value);
        if (! $vehicleTradeIn) {
            return [];
        }

        return [
            'estimatedMileage' => $vehicleTradeIn['estimatedMileage'] ?? '',
            'exteriorColor' => $vehicleTradeIn['exteriorColor'] ?? '',
            'interiorColor' => $vehicleTradeIn['interiorColor'] ?? '',
            'make' => $vehicleTradeIn['make'] ?? '',
            'model' => $vehicleTradeIn['model'] ?? '',
            'trim' => $vehicleTradeIn['trim'] ?? '',
            'vin' => $vehicleTradeIn['vin'] ?? '',
            'year' => $vehicleTradeIn['year'] ?? '',
        ];
    }
}
