<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\AeroAmbulancia\Services\AeroAmbulanciaSubscriptionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateAeroAmbulanciaSubscriptionActivity extends KanvasActivity
{
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::AERO_AMBULANCIA,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $data = $this->getActivityData($order, $params);

                $subscriptionService = new AeroAmbulanciaSubscriptionService($app, $order);

                return $subscriptionService->createNewSubscription(
                    $data['people'],
                    $data['subscription_data']
                );
            },
            company: $order->company,
        );
    }

    /**
     * Get all required data for the activity
     */
    protected function getActivityData(Order $order, array $params): array
    {
        $people = $order->people;
        if (! $people instanceof People) {
            throw new ValidationException('Order must have a valid people record');
        }

        $beneficiaries = $order->getMetadata('beneficiaries') ?? $params['beneficiaries'] ?? [];
        if (empty($beneficiaries)) {
            throw new ValidationException('Beneficiaries data is required in order metadata');
        }

        if (! isset($beneficiaries['holder'])) {
            throw new ValidationException('Holder data is required in beneficiaries metadata');
        }

        return [
            'people' => $people,
            'subscription_data' => [
                'beneficiaries' => $beneficiaries,
            ],
        ];
    }
}
