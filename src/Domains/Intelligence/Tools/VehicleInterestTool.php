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
        $vehiculeInterest = $this->entity->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value);
        $variant = Variants::where('sku', $vehiculeInterest['vin'])
                    ->where('companies_id', $this->entity->companies_id)
                    ->where('apps_id', $this->entity->apps_id)
                    ->first();

        return [
            'condition' => $vehiculeInterest['isNew'] ?? '',
            'year' => $vehiculeInterest['yearFrom'] ?? '',
            'make' => $vehiculeInterest['make'] ?? '',
            'model' => $vehiculeInterest['model'] ?? '',
            'trim' => $vehiculeInterest['trim'] ?? '',
            'vin' => $vehiculeInterest['vin'] ?? '',
            'stock_number' => $vehiculeInterest['stockNumber'] ?? '',
            'isPrimary' => $vehiculeInterest['isPrimary'] ?? '',
            'price' => $variant?->getPriceInfoFromDefaultChannel()->price ?? 0,
        ];
    }
}
