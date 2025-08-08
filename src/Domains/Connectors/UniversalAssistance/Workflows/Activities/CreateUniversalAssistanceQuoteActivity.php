<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\UniversalAssistance\Handlers\UniversalAssistanceHandler;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateUniversalAssistanceQuoteActivity extends KanvasActivity
{
    /**
     * Create a travel insurance quote
     */
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::UNIVERSAL_ASSISTANCE,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $handler = new UniversalAssistanceHandler($app, $order);

                // Get travel data from params or order metadata
                $travelData = $params['travel_data'] ?? $order->metadata['universal_assistance']['travel_data'] ?? [];

                // Get contact person from order
                $contactPerson = $order->peoples()->first();

                return $handler->handleTravelQuote($travelData, $contactPerson);
            },
            company: $order->company,
        );
    }
}
