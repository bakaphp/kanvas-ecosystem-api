<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Jobs\ConfirmParkingPaymentJob;
use Kanvas\Connectors\Movipass\Parking\Actions\DispatchParkingPaymentConfirmationAction;
use Kanvas\Connectors\Movipass\Parking\Actions\QuoteParkingExitAction;
use Kanvas\Connectors\Movipass\Parking\Enums\ParkingStateEnum;
use Kanvas\Connectors\Movipass\Parking\SimulatedParkingOperator;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingStateResolver;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassActivity;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Support\RecordsParkingPayments;
use Tests\Connectors\Traits\CreatesMovipassParkingOrder;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class ParkingQuoteAndPayTest extends TestCase
{
    use CreatesMovipassParkingOrder;
    use HasIntegrationCompany;
    use InventoryCases;
    use RecordsParkingPayments;
    use PaymentCases;

    private const string ENTRY = '2026-11-22T10:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }

        Carbon::setTestNow(Carbon::parse(self::ENTRY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function walkInOrder(bool $active = true): Order
    {
        $order = $this->createMovipassOrder(app(Apps::class));
        $variant = $order->items->first()->variant;

        $variant->variantWarehouses()->each(function ($row): void {
            $row->config = ['rates' => ['hourly' => 0.75]];
            $row->save();
        });

        ParkingMetadata::put($order, [ParkingMetadata::ENTRY_AT => self::ENTRY]);

        if ($active) {
            $order->transitionToStatus($order->user, MovipassOrderStatusEnum::ACTIVE->value);
            $order->refresh();
            $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->orderStatus?->slug);
        }

        return $order;
    }

    private function quoteAfter(Order $order, int $minutes): void
    {
        Carbon::setTestNow(Carbon::parse(self::ENTRY)->addMinutes($minutes));
        new QuoteParkingExitAction($order)->execute();
        $order->refresh();
    }

    private function forceOutcome(Order $order, string $outcome): void
    {
        $order->items->first()->variant->product->set(SimulatedParkingOperator::FORCED_OUTCOME_SETTING, $outcome);
    }

    private function runConfirmation(Order $order): void
    {
        new ConfirmParkingPaymentJob($order->app, $order)->handle();
    }

    public function testSixtyMinutesQuotesOneStartedHourAndRewritesTheTotal(): void
    {
        $order = $this->walkInOrder();

        $this->quoteAfter($order, 60);

        $this->assertSame(0.75, (float) $order->total_net_amount);
        $this->assertSame(0.75, (float) $order->items()->first()->unit_price_net_amount);
        $this->assertSame(0.75, ParkingMetadata::of($order)[ParkingMetadata::QUOTE]['amount']);
        $this->assertNotEmpty(ParkingMetadata::of($order)[ParkingMetadata::QUOTE]['reference']);
    }

    public function testSixtyOneMinutesQuotesTwoStartedHoursAndRequoteReplacesTheQuote(): void
    {
        $order = $this->walkInOrder();

        $this->quoteAfter($order, 30);
        $this->quoteAfter($order, 61);

        $this->assertSame(1.5, (float) $order->total_net_amount);
        $this->assertSame(1.5, ParkingMetadata::of($order)[ParkingMetadata::QUOTE]['amount']);
    }

    public function testAQuoteOnANonActiveOrderIsRefused(): void
    {
        $order = $this->walkInOrder(active: false);

        $this->expectException(ValidationException::class);

        new QuoteParkingExitAction($order)->execute();
    }

    public function testAnOrderWithoutEntryIsNotQuotable(): void
    {
        $order = $this->walkInOrder();
        ParkingMetadata::put($order, [ParkingMetadata::ENTRY_AT => '']);

        $this->expectException(ValidationException::class);

        new QuoteParkingExitAction($order)->execute();
    }

    public function testARejectedPaymentKeepsTheOrderActiveAndInProgress(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);

        $this->addPayment($order, 0.75, PaymentStatusEnum::FAILED);
        $order->refresh();

        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->orderStatus?->slug);
        $this->assertSame(ParkingStateEnum::IN_PROGRESS, ParkingStateResolver::for($order));
        $this->assertFalse(ParkingQuotePayment::isPaid($order));

        Bus::fake();
        $this->assertFalse(new DispatchParkingPaymentConfirmationAction($order)->execute());
        Bus::assertNotDispatched(ConfirmParkingPaymentJob::class);
    }

    public function testAPaidAmountDifferentFromTheQuoteDoesNotCount(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);

        $this->addPayment($order, 0.5, PaymentStatusEnum::PAID);

        $this->assertFalse(ParkingQuotePayment::isPaid($order));
        $this->assertSame(ParkingStateEnum::IN_PROGRESS, ParkingStateResolver::for($order));
    }

    public function testApprovedButUnconfirmedIsPendingAndARetryConfirmsWithASinglePayment(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);
        $this->addPayment($order, 0.75, PaymentStatusEnum::PAID);

        $this->assertTrue(ParkingQuotePayment::isPaid($order));
        $this->assertSame(ParkingStateEnum::PAYMENT_APPROVED, ParkingStateResolver::for($order));

        Bus::fake();
        $this->assertTrue(new DispatchParkingPaymentConfirmationAction($order)->execute());
        Bus::assertDispatched(ConfirmParkingPaymentJob::class);

        $this->forceOutcome($order, SimulatedParkingOperator::DENY_CONFIRMATION);

        $this->runConfirmation($order);
        $order->refresh();

        $this->assertSame(ParkingStateEnum::PENDING_CONFIRMATION, ParkingStateResolver::for($order));
        $this->assertArrayNotHasKey(ParkingMetadata::PAYMENT_CONFIRMED_AT, ParkingMetadata::of($order));

        $this->forceOutcome($order, '');
        $this->runConfirmation($order);
        $order->refresh();

        $this->assertSame(ParkingStateEnum::EXIT_ENABLED, ParkingStateResolver::for($order));
        $this->assertSame(1, $order->payments()->count());
    }

    public function testAConfirmedVisitIsNotConfirmedOrDispatchedAgain(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);
        $this->addPayment($order, 0.75, PaymentStatusEnum::PAID);
        $this->runConfirmation($order);
        $order->refresh();

        Bus::fake();
        $this->assertFalse(new DispatchParkingPaymentConfirmationAction($order)->execute());
        Bus::assertNotDispatched(ConfirmParkingPaymentJob::class);
    }

    public function testOncePaidTheQuoteIsFixed(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);
        $this->addPayment($order, 0.75, PaymentStatusEnum::PAID);

        $this->expectException(ValidationException::class);

        $this->quoteAfter($order, 180);
    }

    public function testACompletedVisitIsFinished(): void
    {
        $order = $this->walkInOrder();
        $order->transitionToStatus($order->user, MovipassOrderStatusEnum::COMPLETED->value);
        $order->refresh();

        $this->assertSame(ParkingStateEnum::FINISHED, ParkingStateResolver::for($order));
    }

    public function testNonParkingOrdersHaveNoParkingState(): void
    {
        $order = $this->walkInOrder();
        ParkingMetadata::put($order, [ParkingMetadata::ENTRY_AT => '']);

        $this->assertNull(ParkingStateResolver::for($order));
    }

    public function testAmountDueIsStoredAndPaymentIsComparedToIt(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);

        $quote = ParkingMetadata::of($order)[ParkingMetadata::QUOTE];
        $this->assertSame(0.75, $quote['amount_due']);

        ParkingMetadata::put($order, [
            ParkingMetadata::QUOTE => [...$quote, 'amount_due' => 0.8],
        ]);
        $this->addPayment($order, 0.75, PaymentStatusEnum::PAID);

        $this->assertFalse(ParkingQuotePayment::isPaid($order->refresh()));
    }

    public function testAQuoteIsRefusedWhileAPaymentIsOpen(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);
        $this->addPayment($order, 0.75, PaymentStatusEnum::PENDING_AUTHORIZATION);

        $this->expectException(ValidationException::class);

        $this->quoteAfter($order, 120);
    }

    public function testAQuoteIsRefusedOnceAnyPaymentIsPaid(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);
        $this->addPayment($order, 0.5, PaymentStatusEnum::PAID);

        $this->expectException(ValidationException::class);

        $this->quoteAfter($order, 120);
    }

    public function testACancelledVisitIsCancelledNotInProgress(): void
    {
        $order = $this->walkInOrder();
        $order->setRelation('orderStatus', new OrderStatus(['slug' => MovipassOrderStatusEnum::CANCELLED->slug()]));

        $this->assertSame(ParkingStateEnum::CANCELLED, ParkingStateResolver::for($order));
    }

    public function testPayingThroughThePaymentModelKeepsTheOrderActiveAndDispatchesTheConfirmation(): void
    {
        $order = $this->walkInOrder();
        $this->quoteAfter($order, 60);
        $payment = $this->addPayment($order, 0.75, PaymentStatusEnum::PENDING);

        Bus::fake();
        $payment->markAsPaid();
        $order->refresh();

        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->orderStatus?->slug);

        new SyncMovipassActivity(0, now()->toDateTimeString(), new StoredWorkflow(), [])->execute(
            $order,
            app(Apps::class),
            ['currentEventTypeName' => WorkflowEnum::UPDATED->value]
        );

        Bus::assertDispatched(ConfirmParkingPaymentJob::class);
        $this->assertSame(MovipassOrderStatusEnum::ACTIVE->slug(), $order->refresh()->orderStatus?->slug);
    }
}
