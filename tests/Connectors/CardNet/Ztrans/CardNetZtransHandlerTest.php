<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Ztrans\Handlers\CardNetZtransHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Mockery;
use Tests\TestCase;

class CardNetZtransHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSetupSavesEveryProvidedSetting(): void
    {
        $saved = [];
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('set')->andReturnUsing(function (string $key, mixed $value) use (&$saved): bool {
            $saved[$key] = $value;

            return true;
        });

        $handler = new CardNetZtransHandler(
            $app,
            new Companies(),
            new Regions(),
            [
                'base_url' => 'https://labservicios.cardnet.com.do/api/payment',
                'merchant_id' => '100000001',
                'terminal_id' => '00000001',
                'three_ds_base_url' => 'https://labservicios.cardnet.com.do/servicios/3ds/server',
                'three_ds_integrator_code' => '100000001',
                'three_ds_api_key' => 'secret',
                'use_hold' => '1',
            ]
        );

        $this->assertTrue($handler->setup());
        $this->assertSame('100000001', $saved[ConfigurationEnum::MERCHANT_ID->value]);
        $this->assertSame('secret', $saved[ConfigurationEnum::THREE_DS_API_KEY->value]);
        $this->assertSame('1', $saved[ConfigurationEnum::USE_HOLD->value]);
        $this->assertArrayNotHasKey(ConfigurationEnum::REFUND_BEARER_TOKEN->value, $saved);
    }

    public function testSetupRejectsMissingCredentials(): void
    {
        $handler = new CardNetZtransHandler(
            Mockery::mock(Apps::class)->makePartial(),
            new Companies(),
            new Regions(),
            ['merchant_id' => '100000001']
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('three_ds_api_key');

        $handler->setup();
    }
}
