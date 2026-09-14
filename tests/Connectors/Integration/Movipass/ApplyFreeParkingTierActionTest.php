<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\ApplyFreeParkingTierAction;
use Kanvas\Connectors\Movipass\Enums\ProductAttributeEnum;
use Tests\Connectors\Traits\CreatesMovipassParkingOrder;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class ApplyFreeParkingTierActionTest extends TestCase
{
    use CreatesMovipassParkingOrder;
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    public function testAppliesFreeWindowAndZeroesTheOrder(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [], [$this->freeMinutesAttribute(150)]);

        $applied = new ApplyFreeParkingTierAction($order)->execute();

        $order->refresh();

        $this->assertTrue($applied);
        $this->assertTrue($order->metadata['data']['free_tier']);
        $this->assertEquals(150, $order->metadata['data']['free_minutes']);
        $this->assertEquals(
            Carbon::parse($order->metadata['data']['start_at'])->addMinutes(150)->toDateTimeString(),
            $order->metadata['data']['end_at']
        );
        $this->assertEquals(0.0, (float) $order->total_gross_amount);
        $this->assertEquals(0.0, (float) $order->total_net_amount);
        $this->assertEquals(0.0, (float) $order->items->first()->unit_price_net_amount);
    }

    public function testEndAtFromThePayloadIsIgnored(): void
    {
        $app = app(Apps::class);
        $startAt = now()->toDateTimeString();

        $order = $this->createMovipassOrder(
            $app,
            [
                'start_at' => $startAt,
                'end_at' => now()->addHours(8)->toDateTimeString(),
            ],
            [$this->freeMinutesAttribute(150)]
        );

        new ApplyFreeParkingTierAction($order)->execute();

        $order->refresh();

        $this->assertEquals(
            Carbon::parse($startAt)->addMinutes(150)->toDateTimeString(),
            $order->metadata['data']['end_at']
        );
    }

    public function testExtensionOrderIsNeverFree(): void
    {
        $app = app(Apps::class);

        $reservation = $this->createMovipassOrder($app, [], [$this->freeMinutesAttribute(150)]);
        $extension = $this->createMovipassOrder($app, [], [$this->freeMinutesAttribute(150)]);
        $extension->parent_id = $reservation->getId();
        $extension->saveQuietly();

        $applied = new ApplyFreeParkingTierAction($extension)->execute();

        $extension->refresh();

        $this->assertFalse($applied);
        $this->assertArrayNotHasKey('free_tier', $extension->metadata['data']);
        $this->assertGreaterThan(0.0, (float) $extension->total_gross_amount);
    }

    public function testOrderWithoutTheAttributeIsUntouched(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app);

        $applied = new ApplyFreeParkingTierAction($order)->execute();

        $order->refresh();

        $this->assertFalse($applied);
        $this->assertArrayNotHasKey('free_tier', $order->metadata['data']);
        $this->assertArrayNotHasKey('end_at', $order->metadata['data']);
        $this->assertGreaterThan(0.0, (float) $order->total_gross_amount);
    }

    private function freeMinutesAttribute(int $minutes): array
    {
        return [
            'name' => ProductAttributeEnum::PARKING_FREE_MINUTES->value,
            'value' => $minutes,
        ];
    }
}
