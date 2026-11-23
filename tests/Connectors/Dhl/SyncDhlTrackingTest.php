<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use ArrayObject;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Actions\SyncDhlTrackingAction;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Traits\CreatesDhlOrder;
use Tests\TestCase;

#[Group('serial')]
final class SyncDhlTrackingTest extends TestCase
{
    use CreatesDhlOrder;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'commerce', 'inventory', 'crm', 'ecosystem'];

    private Companies $company;
    private ArrayObject $documents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Companies::factory()->create();
        $this->configureDhl($this->company);
        $this->setUpDhlOrderStatuses();
        $this->documents = $this->fakeDhlDocuments();
        Notification::fake();
    }

    public function testADeliveredShipmentMovesTheOrderStoresTheProofAndFulfillsOnce(): void
    {
        $order = $this->dispatchedOrder('1111111111');
        $this->fakeTracking('1111111111', [
            $this->dhlEvent('PU', '2026-11-10', '09:00:00', 'Shipment picked up'),
            $this->dhlEvent('OK', '2026-11-11', '14:30:00', 'Delivered', 'M PEREZ'),
        ]);

        $summary = $this->sync();

        $order->refresh();
        $this->assertSame(['polled' => 1, 'transitioned' => 1, 'epod_stored' => 0], $summary);
        $this->assertSame('delivered', $this->statusSlugOf($order));
        $this->assertTrue($order->isFulfilled());
        $this->assertSame('OK', $order->get(CustomFieldEnum::TRACKING->value)['events'][1]['typeCode']);
        $this->assertSame('2026-11-11 14:30:00', $order->get(CustomFieldEnum::DELIVERED_AT->value));
        $this->assertNotNull($order->get(CustomFieldEnum::EPOD_STORED_AT->value));
        $this->assertCount(1, $this->documents);
    }

    public function testEventsAreOrderedByTimeSoTheLatestOneDrivesTheTransition(): void
    {
        $order = $this->dispatchedOrder('2222222222');
        $this->fakeTracking('2222222222', [
            $this->dhlEvent('OK', '2026-11-11', '14:30:00'),
            $this->dhlEvent('PU', '2026-11-10', '09:00:00'),
        ]);

        $this->sync();

        $this->assertSame('delivered', $this->statusSlugOf($order));
    }

    public function testAReturnToShipperMovesTheOrderToReturnedWithoutFetchingTheProof(): void
    {
        $order = $this->dispatchedOrder('3333333333');
        $this->fakeTracking('3333333333', [$this->dhlEvent('RT', '2026-11-12', '10:00:00', 'Returned to shipper')]);

        $this->sync();

        $this->assertSame('returned', $this->statusSlugOf($order));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'proof-of-delivery'));
    }

    public function testANotHomeAttemptAndAnUnmappedScanKeepTheSnapshotWithoutATransition(): void
    {
        $notHome = $this->dispatchedOrder('4444444444');
        $scanned = $this->dispatchedOrder('5555555555');
        $this->fakeTracking('4444444444', [$this->dhlEvent('NH', '2026-11-11', '11:00:00', 'Not home')], '5555555555', [
            $this->dhlEvent('PL', '2026-11-11', '08:00:00', 'Processed at location'),
        ]);

        $summary = $this->sync();

        $this->assertSame(2, $summary['polled']);
        $this->assertSame(0, $summary['transitioned']);
        $this->assertSame('dispatched', $this->statusSlugOf($notHome));
        $this->assertSame('dispatched', $this->statusSlugOf($scanned));
        $this->assertSame('NH', $notHome->get(CustomFieldEnum::TRACKING->value)['events'][0]['typeCode']);
        $this->assertSame('PL', $scanned->get(CustomFieldEnum::TRACKING->value)['events'][0]['typeCode']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/tracking') && str_contains(urldecode($request->url()), '4444444444') && str_contains(urldecode($request->url()), '5555555555') && substr_count(urldecode($request->url()), 'shipmentTrackingNumber') === 1);
    }

    public function testAMissingProofIsRetriedOnTheNextRunAndStoredOnlyOnce(): void
    {
        $order = $this->dispatchedOrder('6666666666');
        Http::fake([
            self::DHL_HOST . '/tracking*' => Http::response($this->dhlTrackingResponse('6666666666', [$this->dhlEvent('OK', '2026-11-11', '14:30:00')])),
            self::DHL_HOST . '/shipments/6666666666/proof-of-delivery*' => Http::sequence()
                ->push(['title' => 'Not Found'], 404)
                ->push($this->dhlEpodResponse()),
        ]);

        $first = $this->sync();
        $this->assertSame('delivered', $this->statusSlugOf($order));
        $this->assertNull($order->get(CustomFieldEnum::EPOD_STORED_AT->value));
        $this->assertCount(0, $this->documents);

        $second = $this->sync();
        $third = $this->sync();

        $this->assertSame(1, $second['epod_stored']);
        $this->assertSame(0, $third['epod_stored']);
        $this->assertCount(1, $this->documents);
        $this->assertSame(0, $first['epod_stored']);
    }

    public function testAnAwbDhlHasNotScannedYetDoesNotStopThePoll(): void
    {
        $booked = $this->dispatchedOrder('9090909090');
        $delivered = $this->dhlOrder($this->company, 'delivered', [
            'tracking_client_id' => '9191919191',
            'shipped_date' => Carbon::now()->toDateTimeString(),
        ]);
        Http::fake([
            self::DHL_HOST . '/tracking*' => Http::response(['title' => 'No data found', 'status' => '404'], 404),
            self::DHL_HOST . '/shipments/9191919191/proof-of-delivery*' => Http::response($this->dhlEpodResponse()),
        ]);

        $summary = $this->sync();

        $this->assertSame(['polled' => 0, 'transitioned' => 0, 'epod_stored' => 1], $summary);
        $this->assertSame('dispatched', $this->statusSlugOf($booked));
        $this->assertNotNull($delivered->get(CustomFieldEnum::EPOD_STORED_AT->value));
    }

    public function testManualReviewAndStaleOrdersAreNotPolled(): void
    {
        $this->fakeDhl();
        $review = $this->dispatchedOrder('7777777777');
        $review->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::MANUAL_REVIEW->value);
        $this->dispatchedOrder('8888888888', Carbon::now()->subDays(SyncDhlTrackingAction::STALE_AFTER_DAYS + 1));

        $summary = $this->sync();

        $this->assertSame(0, $summary['polled']);
        Http::assertNothingSent();
    }

    private function sync(): array
    {
        return new SyncDhlTrackingAction(app(Apps::class), $this->company)->execute();
    }

    private function dispatchedOrder(string $awb, ?Carbon $shippedAt = null): Order
    {
        return $this->dhlOrder($this->company, 'dispatched', [
            'tracking_client_id' => $awb,
            'shipped_date' => ($shippedAt ?? Carbon::now())->toDateTimeString(),
        ]);
    }

    private function fakeTracking(string $awb, array $events, ?string $secondAwb = null, array $secondEvents = []): void
    {
        $shipments = $this->dhlTrackingResponse($awb, $events)['shipments'];

        if ($secondAwb !== null) {
            $shipments = [...$shipments, ...$this->dhlTrackingResponse($secondAwb, $secondEvents)['shipments']];
        }

        Http::fake([
            self::DHL_HOST . '/tracking*' => Http::response(['shipments' => $shipments]),
            self::DHL_HOST . '/shipments/*/proof-of-delivery*' => Http::response($this->dhlEpodResponse()),
        ]);
    }
}
