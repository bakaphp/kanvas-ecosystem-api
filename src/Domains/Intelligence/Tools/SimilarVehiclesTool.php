<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Kanvas\Inventory\Variants\Models\Variants;
use Override;

class SimilarVehiclesTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $additional_context_information = $this->entity->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value);
        $vehicleInterest = $additional_context_information['vehicle_interest'];

        if (empty($vehicleInterest['make']) || empty($vehicleInterest['model'])) {
            return [];
        }

        $relatedVariant = Variants::searchByMultipleAttributes(
            app: $this->entity->app,
            attributes: [
                ['name' => 'make', 'value' => $vehicleInterest['make'] ?? null],
                ['name' => 'model', 'value' => $vehicleInterest['model'] ?? null],
                //['name' => 'year', 'value' => $vehicleInterest['yearFrom'] ?? null],
            ],
            locale: 'en',
            user: null,
            company: $this->entity->company,
        )->select('products_variants.uuid', 'products_variants.name')->limit(10)->get();

        return $relatedVariant->toArray();
    }
}
