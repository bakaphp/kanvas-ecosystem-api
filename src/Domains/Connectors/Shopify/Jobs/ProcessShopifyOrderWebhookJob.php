<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use Exception;
use Kanvas\Connectors\Shopify\Actions\SyncShopifyOrderAction;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessShopifyOrderWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $regionId = $this->receiver->configuration['region_id'];
        $isB2BOrder = (bool) ($this->receiver->configuration['is_b2b_order'] ?? false);

        $syncShopifyOrder = new SyncShopifyOrderAction(
            $this->receiver->app,
            $this->receiver->company,
            Regions::getById($regionId),
            $this->webhookRequest->payload,
        );

        if ($isB2BOrder && ! $this->validateUserCompany($this->webhookRequest->payload)) {
            return [
                'message' => 'Is not a B2B order',
                'order'   => null,
            ];
        }

        $order = $syncShopifyOrder->execute();

        if ($isB2BOrder) {
            $order->addTag('B2B');
        }

        return [
            'message' => 'Order synced successfully',
            'order'   => $order->getId(),
        ];
    }

    private function validateUserCompany(array $payload): bool
    {
        try {
            $user = Users::getByEmail($payload['contact_email'] ?? null);
            if (! $user) {
                return false;
            }

            return (bool) UsersRepository::belongsToThisApp($user, $this->receiver->app, $this->receiver->company);
        } catch (Exception) {
            return false;
        }
    }
}
