<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\EasyActivation;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EasyActivation\Enums\ConfigurationEnum;
use Kanvas\Connectors\EasyActivation\Services\OrderService;
use Tests\TestCase;

final class OrderServiceTest extends TestCase
{
    public function testOrderStatus(): void
    {
        $app = app(Apps::class);

        $app->set(ConfigurationEnum::EASY_ACTIVATION_USERNAME->value, getenv('EASY_ACTIVATION_USERNAME'));
        $app->set(ConfigurationEnum::EASY_ACTIVATION_PASSWORD->value, getenv('EASY_ACTIVATION_PASSWORD'));

        $orderService = new OrderService($app);
        $orderStatus = $orderService->checkStatus(getenv('TEST_EASY_ACTIVATION_ICCID'));

        $this->assertArrayHasKey('status', $orderStatus);
        $this->assertArrayHasKey('esim_status', $orderStatus);
        $this->assertArrayHasKey('iccid', $orderStatus);
        $this->assertEquals(getenv('TEST_EASY_ACTIVATION_ICCID'), $orderStatus['iccid']);
    }
}
