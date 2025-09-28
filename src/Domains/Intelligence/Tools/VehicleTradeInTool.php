<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class VehicleTradeInTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $vehicleTradeIn = $this->entity->get(LeadCustomFieldEnum::TRADE_IN->value);
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
