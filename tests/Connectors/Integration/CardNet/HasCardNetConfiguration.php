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
            email: env('CARDNET_TEST_EMAIL', 'test@example.com'),
            pan: env('CARDNET_TEST_PAN'),
            cvv: env('CARDNET_TEST_CVV'),
            expiration: env('CARDNET_TEST_EXPIRATION'),
            titular: env('CARDNET_TEST_TITULAR', 'Test User'),
            customerId: $customerId,
        );
    }

    protected function createTestCustomer(): array
    {
        return $this->getCardNetService()->createCustomer(
            email: 'test@example.com',
            firstName: 'Test',
            lastName: 'User',
        );
    }
}
