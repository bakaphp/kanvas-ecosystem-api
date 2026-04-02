<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\CardNet;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CardNet\Client;
use Kanvas\Connectors\CardNet\DataTransferObject\CardDetail;
use Kanvas\Connectors\CardNet\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Services\CardNetService;

trait HasCardNetConfiguration
{
    protected function setUpCardNetConfiguration(): void
    {
        $app = app(Apps::class);
        $app->set(
            ConfigurationEnum::PRIVATE_KEY->value,
            env('CARDNET_PRIVATE_KEY'),
        );
        $app->set(
            ConfigurationEnum::PUBLIC_KEY->value,
            env('CARDNET_PUBLIC_KEY'),
        );
        $app->set(
            ConfigurationEnum::BASE_URL->value,
            env('CARDNET_BASE_URL', ConfigurationEnum::SANDBOX_URL->value),
        );
    }

    protected function getCardNetService(): CardNetService
    {
        $app = app(Apps::class);

        return new CardNetService(new Client($app));
    }

    protected function getTestCardDetail(int $customerId): CardDetail
    {
        return new CardDetail(
            email: 'test@example.com',
            pan: '4507650000000048',
            cvv: '123',
            expiration: '202812',
            titular: 'TEST USER',
            customerId: $customerId,
        );
    }

    protected function createTestCustomer(): array
    {
        return $this->getCardNetService()->createCustomer(
            email: 'test-' . time() . '@cardnettest.com',
            firstName: 'Test',
            lastName: 'User',
        );
    }
}
