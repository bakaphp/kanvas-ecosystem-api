<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Domains\Connectors\AeroAmbulancia\Client;
use Kanvas\Domains\Connectors\AeroAmbulancia\Services\AeroAmbulanciaSubscriptionService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;

class CreateAeroAmbulanciaSubscriptionActivity implements WorkflowActivityInterface
{
    /**
     * Execute the activity
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        if (! $entity instanceof Order) {
            throw new ValidationException('Entity must be an Order');
        }

        $data = $this->getActivityData($entity, $params);

        $client = new Client($app, $entity->company);
        $subscriptionService = new AeroAmbulanciaSubscriptionService($client);

        return $subscriptionService->createNewSubscription(
            $data['people'],
            $data['subscription_data']
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
                'beneficiaries' => $beneficiaries
            ]
        ];
    }
}
