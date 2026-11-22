<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Notifications\PaymentReceiptNotification;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Support\WalkInOrder;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingPassScannedActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Event\Concerns\BuildsWalkInParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;

final class ParkingPassScannedActivityTest extends TestCase
{
    use BuildsWalkInParking;
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use InventoryCases;

    private const string SCANNED_AT = '2026-11-22T10:00:00+00:00';
    private const string EXITED_AT = '2026-11-22T11:00:00+00:00';

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

    public function testEntryAtComesFromTheScanTimeNotTheRunTime(): void
    {
        [$booking, $order] = $this->walkInBooking();
        Carbon::setTestNow(Carbon::parse(self::SCANNED_AT)->addHours(3));

        $this->runActivity($booking, useNumber: 1);

        $order->refresh();
        $this->assertSame(
            Carbon::parse(self::SCANNED_AT)->toIso8601String(),
            ParkingMetadata::of($order)[ParkingMetadata::ENTRY_AT]
        );
        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->orderStatus->slug);
    }

    public function testARepeatedFirstUseEventIsANoOp(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->runActivity($booking, useNumber: 1);

        $later = Carbon::parse(self::SCANNED_AT)->addMinutes(30)->toIso8601String();
        $result = $this->runActivity($booking, useNumber: 1, scannedAt: $later);

        $order->refresh();
        $this->assertTrue($result['skipped']);
        $this->assertSame(
            Carbon::parse(self::SCANNED_AT)->toIso8601String(),
            ParkingMetadata::of($order)[ParkingMetadata::ENTRY_AT]
        );
        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->orderStatus->slug);
    }

    public function testTheLastUseCompletesTheOrderWithExitCommissionAndReceipt(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->runActivity($booking, useNumber: 1);
        $this->addPaidPayment($order);

        $this->runActivity($booking, useNumber: 2, scannedAt: self::EXITED_AT);

        $order->refresh();
        $this->assertSame(MovipassOrderStatusEnum::COMPLETED->slug(), $order->orderStatus->slug);
        $this->assertSame(
            Carbon::parse(self::EXITED_AT)->toIso8601String(),
            ParkingMetadata::of($order)['exit_at']
        );
        $this->assertArrayHasKey('commission', $order->metadata);
        Notification::assertSentOnDemand(PaymentReceiptNotification::class);
    }

    public function testARepeatedLastUseEventIsANoOp(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->runActivity($booking, useNumber: 1);
        $this->runActivity($booking, useNumber: 2, scannedAt: self::EXITED_AT);

        $result = $this->runActivity(
            $booking,
            useNumber: 2,
            scannedAt: Carbon::parse(self::EXITED_AT)->addHour()->toIso8601String()
        );

        $order->refresh();
        $this->assertTrue($result['skipped']);
        $this->assertSame(
            Carbon::parse(self::EXITED_AT)->toIso8601String(),
            ParkingMetadata::of($order)['exit_at']
        );
    }

    public function testAnExitOnAnOrderThatNeverEnteredIsSkipped(): void
    {
        [$booking, $order] = $this->walkInBooking();

        $result = $this->runActivity($booking, useNumber: 2, scannedAt: self::EXITED_AT);

        $order->refresh();
        $this->assertTrue($result['skipped']);
        $this->assertSame(MovipassOrderStatusEnum::CREATED->slug(), $order->orderStatus->slug);
    }

    public function testAWalkInOrderGetsNoFreeTierNorEndAt(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->runActivity($booking, useNumber: 1);
        $this->runActivity($booking, useNumber: 2, scannedAt: self::EXITED_AT);

        $order->refresh();
        $this->assertArrayNotHasKey('end_at', $order->metadata['data']);
        $this->assertArrayNotHasKey('free_tier', $order->metadata['data']);
        $this->assertArrayNotHasKey('free_minutes', $order->metadata['data']);
    }

    public function testAFailedCompletedTransitionSendsNoCommissionNorReceipt(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->runActivity($booking, useNumber: 1);
        $this->addPaidPayment($order);
        $order->refresh();
        OrderStatusTransitions::where('from_status_id', $order->order_status_id)->delete();

        $result = $this->runActivity($booking, useNumber: 2, scannedAt: self::EXITED_AT);

        $order->refresh();
        $this->assertTrue($result['skipped']);
        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->orderStatus->slug);
        $this->assertArrayNotHasKey('commission', $order->metadata);
        $this->assertArrayNotHasKey('exit_at', ParkingMetadata::of($order));
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 0);
    }

    public function testAnEntryOnAnOrderThatIsNotCreatedIsSkipped(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $order->transitionToStatus($order->user, MovipassOrderStatusEnum::ACTIVE->value);

        $result = $this->runActivity($booking, useNumber: 1);

        $this->assertTrue($result['skipped']);
        $this->assertArrayNotHasKey(ParkingMetadata::ENTRY_AT, ParkingMetadata::of($order->refresh()));
    }

    public function testARepeatedExitSendsASingleReceipt(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $this->runActivity($booking, useNumber: 1);
        $this->addPaidPayment($order);

        $this->runActivity($booking, useNumber: 2, scannedAt: self::EXITED_AT);
        $this->runActivity($booking, useNumber: 2, scannedAt: self::EXITED_AT);

        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

    public function testABookingWithoutAnOrderIsSkipped(): void
    {
        [$booking, $order] = $this->walkInBooking();
        $booking->event->orders()->delete();

        $result = $this->runActivity($booking, useNumber: 1);

        $this->assertTrue($result['skipped']);
    }

    public function testAnOrderThatIsNotEventBackedIsNotWalkIn(): void
    {
        [, $order] = $this->walkInBooking();
        $this->assertTrue(WalkInOrder::is($order));

        $order->resources_type = null;
        $order->saveQuietly();

        $this->assertFalse(WalkInOrder::is($order));
    }

    private function walkInBooking(): array
    {
        $this->buildWalkInParking();
        $booking = $this->book($this->startedSlot(), '404040');
        $order = $booking->event->orders()->firstOrFail();
        $this->assertSame(MovipassOrderStatusEnum::CREATED->slug(), $order->orderStatus->slug);

        return [$booking, $order];
    }

    private function runActivity(
        EventVersion $booking,
        int $useNumber,
        string $scannedAt = self::SCANNED_AT,
        int $maxUses = 2
    ): array {
        $activity = new ParkingPassScannedActivity(0, now()->toDateTimeString(), new StoredWorkflow(), []);

        return $activity->execute(
            $booking->refresh(),
            $this->apps,
            [
                'use_number' => $useNumber,
                'max_uses' => $maxUses,
                'scanned_at' => $scannedAt,
            ]
        );
    }

    private function addPaidPayment(Order $order): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $order->apps_id;
        $payment->companies_id = $order->companies_id;
        $payment->users_id = $order->users_id;
        $payment->payment_methods_id = 0;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->concept = 'Parking exit';
        $payment->payment_date = date('Y-m-d');
        $payment->amount = 0.75;
        $payment->currency = 'USD';
        $payment->status = PaymentStatusEnum::PAID->value;
        $payment->processor = 'test';
        $payment->metadata = ['data' => []];
        $payment->saveOrFail();

        return $payment;
    }
}
