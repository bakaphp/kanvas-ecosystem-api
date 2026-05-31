<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Inventory\Variants\Models\Variants;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Vehicle Interest')]
class VehicleInterestTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'get_vehicle_interest',
            description: 'Get detailed information about the lead\'s vehicle of interest'
                . '  , including condition, year, make, model, VIN, stock number, price, and inventory status.',
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

        $vehicleInterest = $lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value);
        if (! $vehicleInterest) {
            return [];
        }

        $variantChannelInfo = null;
        $variant = null;
        if (isset($vehicleInterest['vin'])) {
            $variant = Variants::where('sku', $vehicleInterest['vin'])
                ->where('companies_id', $lead->companies_id)
                ->where('apps_id', $lead->apps_id)
                ->first();

            $variantChannelInfo = $variant?->getChannelInfo();
        }

        return [
            'condition' => $vehicleInterest['isNew'] ?? '',
            'year' => $vehicleInterest['yearFrom'] ?? $vehicleInterest['year'] ?? '',
            'make' => $vehicleInterest['make'] ?? '',
            'model' => $vehicleInterest['model'] ?? '',
            'trim' => $vehicleInterest['trim'] ?? '',
            'vin' => $vehicleInterest['vin'] ?? '',
            'stock_number' => $vehicleInterest['stockNumber'] ?? '',
            'in_stock' => (bool) $variantChannelInfo?->is_published,
            'isPrimary' => $vehicleInterest['isPrimary'] ?? '',
            'price' => $variant?->getPriceInfoFromDefaultChannel()->price ?? 0,
            'uuid' => $variant?->product->uuid ?? '',
            'variant_uuid' => $variant?->uuid ?? '',
        ];
    }
}
