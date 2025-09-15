<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
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
        return [
            'condition' => $vehiculeInterest['isNew'] ?? '',
            'year' => $vehiculeInterest['yearFrom'] ?? '',
            'make' => $vehiculeInterest['make'] ?? '',
            'model' => $vehiculeInterest['model'] ?? '',
            'trim' => $vehiculeInterest['trim'] ?? '',
            'vin' => $vehiculeInterest['vin'] ?? '',
            'stock_number' => $vehiculeInterest['stockNumber'] ?? '',
            'isPrimary' => $vehiculeInterest['isPrimary'] ?? '',
        ];
    }
}
