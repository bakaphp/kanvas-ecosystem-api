<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Kanvas\Inventory\Variants\Models\Variants;
use Override;

class VehicleInterestTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $vehicleInterest = $this->entity->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value);
        if (! $vehicleInterest) {
            return [];
        }
        $variant = Variants::where('sku', $vehicleInterest['vin'])
                    ->where('companies_id', $this->entity->companies_id)
                    ->where('apps_id', $this->entity->apps_id)
                    ->first();

        return [
            'condition' => $vehicleInterest['isNew'] ?? '',
            'year' => $vehicleInterest['yearFrom'] ?? '',
            'make' => $vehicleInterest['make'] ?? '',
            'model' => $vehicleInterest['model'] ?? '',
            'trim' => $vehicleInterest['trim'] ?? '',
            'vin' => $vehicleInterest['vin'] ?? '',
            'stock_number' => $vehicleInterest['stockNumber'] ?? '',
            'isPrimary' => $vehicleInterest['isPrimary'] ?? '',
            'price' => $variant?->getPriceInfoFromDefaultChannel()->price ?? 0,
        ];
    }
}
