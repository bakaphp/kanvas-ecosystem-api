<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\ProductAttributeEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassActivity;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\CreatesMovipassParkingOrder;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class SyncMovipassActivityTest extends TestCase
{
    use CreatesMovipassParkingOrder;
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }
    }

    private function createActiveDiscount(Apps $app, int $companiesId): Discount
    {
        $discountType = DiscountType::firstOrCreate(
            ['apps_id' => $app->getId(), 'name' => 'General'],
            ['apps_id' => $app->getId(), 'name' => 'General', 'is_deleted' => false]
        );

        return Discount::withoutSyncingToSearch(function () use ($app, $companiesId, $discountType): Discount {
            $discount = new Discount();
            $discount->apps_id = $app->getId();
            $discount->companies_id = $companiesId;
            $discount->name = 'Test Parking Discount';
            $discount->code = 'PARK' . strtoupper(fake()->lexify('????'));
            $discount->discount_type_id = $discountType->id;
            $discount->value = 20;
            $discount->is_percentage = true;
            $discount->min_order_value = 0;
            $discount->max_discount_amount = null;
            $discount->is_active = true;
            $discount->is_one_per_customer = false;
            $discount->usage_count = 0;
            $discount->usage_limit = null;
            $discount->is_deleted = false;
            $discount->saveOrFail();

            return $discount;
        });
    }

    public function testApplyDiscountFromMetadata(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $discount = $this->createActiveDiscount($app, $company->getId());

        $order = $this->createMovipassOrder($app, [
            'discount_id' => $discount->getId(),
        ]);

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $order->orderDiscounts()->count());
        $this->assertEquals($discount->name, $order->discount_name);
        $this->assertEquals($discount->getId(), $order->voucher_id);
        $this->assertEquals($discount->code, $order->metadata['data']['discount_code']);
        $this->assertEquals($discount->name, $order->metadata['data']['discount_name']);
        $this->assertLessThan($order->total_gross_amount, $order->total_net_amount);
        $expectedDiscount = $order->total_gross_amount * 0.20;
        $this->assertEquals($expectedDiscount, $order->discount_amount);
        $this->assertEquals($order->total_gross_amount - $expectedDiscount, $order->total_net_amount);
    }

    public function testInvalidDiscountIdSkipsGracefully(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [
            'discount_id' => 99999,
        ]);

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(0, $order->orderDiscounts()->count());
        $this->assertNull($order->discount_name);
        $this->assertNull($order->voucher_id);
    }

    public function testNoDiscountIdInMetadataIsNoop(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app);

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(0, $order->orderDiscounts()->count());
        $this->assertNull($order->discount_name);
        $this->assertNull($order->voucher_id);
    }

    public function testCreatedOrderWithoutVehiclePlateUpdatesReference(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app);
        $metadata = $order->metadata;
        unset($metadata['data']['vehiclePlate']);
        $order->metadata = $metadata;
        $order->saveQuietly();

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertSame('Movipass parking test #' . $order->order_number, $order->reference);
    }

    public function testFreeTierOrderActivatesWithoutPayment(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [], [$this->freeMinutesAttribute(150)]);

        $activity = new SyncMovipassActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();

        $this->assertEquals('success', $result['status']);
        $this->assertTrue($order->metadata['data']['free_tier']);
        $this->assertEquals(MovipassOrderStatusEnum::ACTIVE->slug(), $order->orderStatus?->slug);
        $this->assertNotEquals('paid', $order->payment_status);
    }

    private function freeMinutesAttribute(int $minutes): array
    {
        return [
            'name' => ProductAttributeEnum::PARKING_FREE_MINUTES->value,
            'value' => $minutes,
        ];
    }
}
