<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\RecordParkingPassScanAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Jobs\ConfirmParkingPaymentJob;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassScan;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use RuntimeException;
use Tests\Connectors\Support\RecordsParkingPayments;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Event\Concerns\BuildsWalkInParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class ExpireUnusedParkingVisitsCommandTest extends TestCase
{
    use BuildsWalkInParking;
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use InventoryCases;
    use RecordsParkingPayments;

    private const string COMMAND = 'kanvas-movipass:expire-unused-parking-visits';

    protected $apps;
    protected $user;
    protected $company;
    protected $region;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }

        Notification::fake();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);
        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();
        $this->setIntegration(
            $this->apps,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $this->company,
            $this->user
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testAnUnscannedVisitExpiresAfterTheConfiguredMinutes(): void
    {
        [$booking, $order] = $this->walkInBooking(['expire_unused_minutes' => 20]);

        Carbon::setTestNow(now()->addMinutes(21));
        $this->runCommand();

        $order->refresh();
        $this->assertSame(MovipassOrderStatusEnum::CANCELLED->slug(), $order->orderStatus->slug);
        $this->assertTrue((bool) $booking->event->refresh()->isCancelled());
        $this->assertSame(
            0,
            ParticipantPass::query()->where('event_version_id', $booking->getId())->whereNull('revoked_at')->count()
        );
        $this->assertGreaterThan(
            0,
            ParticipantPass::query()->where('event_version_id', $booking->getId())->whereNotNull('revoked_at')->count()
        );
    }

    public function testAVisitYoungerThanTheConfiguredMinutesIsKept(): void
    {
        [, $order] = $this->walkInBooking(['expire_unused_minutes' => 20]);

        Carbon::setTestNow(now()->addMinutes(19));
        $this->runCommand();

        $this->assertSame(MovipassOrderStatusEnum::CREATED->slug(), $order->refresh()->orderStatus->slug);
    }

    public function testAScannedVisitIsNeverExpired(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->enter($booking, $order);

        Carbon::setTestNow(now()->addHours(5));
        $this->runCommand();

        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->refresh()->orderStatus->slug);
        $this->assertSame(
            0,
            ParticipantPass::query()->where('event_version_id', $booking->getId())->whereNotNull('revoked_at')->count()
        );
    }

    public function testAStuckExitIsCompletedWithTheScanTime(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->enter($booking, $order);
        $exitScannedAt = now()->addHour();
        $this->admitExitScan($booking, $exitScannedAt);

        Carbon::setTestNow($exitScannedAt->copy()->addMinutes(10));
        $this->runCommand();

        $order->refresh();
        $this->assertSame(MovipassOrderStatusEnum::COMPLETED->slug(), $order->orderStatus->slug);
        $this->assertSame(
            $exitScannedAt->toIso8601String(),
            ParkingMetadata::of($order)[RecordParkingPassScanAction::EXIT_AT]
        );
    }

    public function testAnExitScannedAMomentAgoIsLeftToTheWorkflow(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->enter($booking, $order);
        $this->admitExitScan($booking, now());

        $this->runCommand();

        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->refresh()->orderStatus->slug);
    }

    public function testAPaidUnconfirmedQuoteRedispatchesTheConfirmation(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->enter($booking, $order);
        ParkingMetadata::put($order, [ParkingMetadata::QUOTE => ['amount_due' => 0.75]]);
        $this->addPayment($order, 0.75);

        Bus::fake();
        $this->runCommand();

        Bus::assertDispatched(ConfirmParkingPaymentJob::class);
    }

    public function testAConfirmedPaymentIsNotRedispatched(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->enter($booking, $order);
        ParkingMetadata::put($order, [
            ParkingMetadata::QUOTE => ['amount_due' => 0.75],
            ParkingMetadata::PAYMENT_CONFIRMED_AT => now()->toIso8601String(),
        ]);
        $this->addPayment($order, 0.75);

        Bus::fake();
        $this->runCommand();

        Bus::assertNotDispatched(ConfirmParkingPaymentJob::class);
    }

    public function testRunningTwiceIsANoOp(): void
    {
        [$booking, $order] = $this->walkInBooking(['expire_unused_minutes' => 20]);
        [$enteredBooking, $enteredOrder] = $this->walkInBooking(vehicle: '505050');
        $this->enter($enteredBooking, $enteredOrder);
        $this->admitExitScan($enteredBooking, now());

        Carbon::setTestNow(now()->addMinutes(30));
        $this->runCommand();
        $this->artisan(self::COMMAND, ['app_id' => $this->apps->getId()])
            ->expectsOutputToContain('expired=0 exits_completed=0')
            ->assertSuccessful();

        $this->assertSame(MovipassOrderStatusEnum::CANCELLED->slug(), $order->refresh()->orderStatus->slug);
        $this->assertSame(MovipassOrderStatusEnum::COMPLETED->slug(), $enteredOrder->refresh()->orderStatus->slug);
        $this->assertTrue($booking->event->refresh()->isCancelled());
    }

    public function testAnOrderWithAPaymentIsNeverExpired(): void
    {
        [, $order] = $this->walkInBooking(['expire_unused_minutes' => 20]);
        $this->addPayment($order, 1.0);

        Carbon::setTestNow(now()->addHours(2));
        $this->runCommand();

        $this->assertSame(MovipassOrderStatusEnum::CREATED->slug(), $order->refresh()->orderStatus->slug);
    }

    public function testAHalfCancelledBookingGetsItsOrderCancelledWithoutReportingAFailure(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $booking->event->update([
            'event_status_id' => EventStatus::firstOrCreate([
                'companies_id' => $booking->companies_id,
                'apps_id' => $booking->apps_id,
                'name' => EventStatusEnum::CANCELLED->value,
            ], ['users_id' => $booking->users_id])->id,
        ]);

        $this->artisan(self::COMMAND, ['app_id' => $this->apps->getId()])
            ->expectsOutputToContain('expired=1 exits_completed=0 confirmations_attempted=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(MovipassOrderStatusEnum::CANCELLED->slug(), $order->refresh()->orderStatus->slug);
    }

    public function testANonWalkInOrderIsIgnored(): void
    {
        [$booking, $order] = $this->walkInBooking(['expire_unused_minutes' => 20]);
        ProductsAttributes::query()->where('products_id', $this->gatedProduct->getId())->delete();
        $this->assertNull(WalkInAccess::forResource($this->gatedProduct->refresh()));
        $status = $order->orderStatus?->slug;

        Carbon::setTestNow(now()->addHours(5));
        $this->runCommand();

        $this->assertSame($status, $order->refresh()->orderStatus?->slug);
        $this->assertFalse($booking->event->refresh()->isCancelled());
    }

    public function testOneFailingOrderIsReportedAndTheNextOneStillRuns(): void
    {
        [$firstBooking, $firstOrder] = $this->walkInBooking(['expire_unused_minutes' => 20]);
        [, $secondOrder] = $this->walkInBooking(['expire_unused_minutes' => 20], '505050');
        $armed = true;
        Event::updating(function (Event $event) use (&$armed, $firstBooking): void {
            if ($armed && $event->getId() === $firstBooking->event_id) {
                throw new RuntimeException('boom');
            }
        });

        Carbon::setTestNow(now()->addMinutes(30));
        $this->artisan(self::COMMAND, ['app_id' => $this->apps->getId()])
            ->expectsOutputToContain('expired=1 exits_completed=0 confirmations_attempted=0 failed=1')
            ->assertFailed();
        $armed = false;

        $this->assertSame(MovipassOrderStatusEnum::CREATED->slug(), $firstOrder->refresh()->orderStatus->slug);
        $this->assertSame(MovipassOrderStatusEnum::CANCELLED->slug(), $secondOrder->refresh()->orderStatus->slug);
    }

    public function testWithoutAnAppIdTheAppsWithOpenEventOrdersAreDiscovered(): void
    {
        [, $order] = $this->walkInBooking(['expire_unused_minutes' => 20]);

        Carbon::setTestNow(now()->addMinutes(30));
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('App ' . $this->apps->getId() . ': expired=1')
            ->assertSuccessful();

        $this->assertSame(MovipassOrderStatusEnum::CANCELLED->slug(), $order->refresh()->orderStatus->slug);
    }

    private function runCommand(): void
    {
        $this->artisan(self::COMMAND, ['app_id' => $this->apps->getId()])->assertSuccessful();
    }

    private function walkInBooking(array $config = [], string $vehicle = '404040'): array
    {
        $this->buildWalkInParking($config);
        $booking = $this->book($this->startedSlot(), $vehicle);

        return [$booking, $booking->event->orders()->firstOrFail()];
    }

    private function enter(EventVersion $booking, Order $order): void
    {
        new RecordParkingPassScanAction(order: $order, useNumber: 1, maxUses: 2, scannedAt: now())->execute();
        $this->passOf($booking)->update(['use_count' => 1]);
        $order->refresh();
    }

    private function admitExitScan(EventVersion $booking, Carbon $scannedAt): void
    {
        $pass = $this->passOf($booking);
        $pass->update(['use_count' => 2, 'max_uses' => 2]);

        ParticipantPassScan::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'participant_passes_id' => $pass->getId(),
            'event_version_id' => $booking->getId(),
            'products_id' => $this->gatedProduct->getId(),
            'users_id' => $this->user->getId(),
            'result' => PassScanResultEnum::ADMITTED,
            'use_number' => 2,
            'scanned_at' => $scannedAt,
        ]);
    }

    private function passOf(EventVersion $booking): ParticipantPass
    {
        return ParticipantPass::query()->where('event_version_id', $booking->getId())->firstOrFail();
    }
}
