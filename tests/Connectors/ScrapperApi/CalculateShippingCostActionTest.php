<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Actions\CalculateShippingCostAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class CalculateShippingCostActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testInsuranceUsesTheFullLineValueAndQuantityThreshold(): void
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturn(null);

        $channel = Mockery::mock(Channels::class)->makePartial();
        $channel->price = 150.0;

        $variant = Mockery::mock(Variants::class)->makePartial();
        $variant->shouldReceive('getAttributeByName')->andReturn(null);
        $variant->shouldReceive('getPriceInfoFromDefaultChannel')->andReturn($channel);

        $result = new CalculateShippingCostAction($app, $variant, 2.0)->execute();

        $this->assertSame(4.8, $result['insurance']);
    }
}
