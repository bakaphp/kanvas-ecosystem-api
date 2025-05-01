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

class CreateAeroAmbulanciaSubscriptionActivity implements WorkflowActivityInterface
{
    /**
     * Execute the activity
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        if (! $entity instanceof Order) {
            throw new \InvalidArgumentException('Entity must be an Order');
        }

        $data = $this->getActivityData($entity, $params);

        $client = new Client($app, $entity->company);
        $subscriptionService = new AeroAmbulanciaSubscriptionService($client);

        return $subscriptionService->createNewSubscription(
            $data['people'],
            $data['subscription_id'],
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
            throw new \InvalidArgumentException('Order must have a valid people record');
        }

        $subscriptionData = $order->getMetadata('subscription_data') ?? $params['subscription_data'] ?? [];
        if (empty($subscriptionData)) {
            throw new \InvalidArgumentException('Subscription data is required');
        }

        $subscriptionId = $order->getMetadata('subscription_id') ?? $params['subscription_id'] ?? null;
        if (! $subscriptionId) {
            throw new \InvalidArgumentException('Subscription ID is required');
        }

        return [
            'people' => $people,
            'subscription_id' => (int) $subscriptionId,
            'subscription_data' => $subscriptionData
        ];
    }
}
