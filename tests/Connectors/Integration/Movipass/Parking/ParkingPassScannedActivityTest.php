<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Notifications\PaymentReceiptNotification;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Support\WalkInOrder;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Tests\Connectors\Support\PreparesMovipassParking;
use Tests\Connectors\Support\RecordsParkingPayments;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class ParkingPassScannedActivityTest extends TestCase
{
    use PreparesMovipassParking;
    use DatabaseTransactions;
    use InventoryCases;
    use RecordsParkingPayments;

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
        $this->addPayment($order, 0.75);

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
        $this->addPayment($order, 0.75);
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
        $this->addPayment($order, 0.75);

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
        [$booking, $order] = $this->bookWalkIn();
        $this->assertSame(MovipassOrderStatusEnum::CREATED->slug(), $order->orderStatus->slug);

        return [$booking, $order];
    }

    private function runActivity(
        EventVersion $booking,
        int $useNumber,
        string $scannedAt = self::SCANNED_AT,
        int $maxUses = 2
    ): array {
        return $this->runParkingPassScanned(
            $booking,
            $useNumber,
            $scannedAt,
            $maxUses
        );
    }
}
