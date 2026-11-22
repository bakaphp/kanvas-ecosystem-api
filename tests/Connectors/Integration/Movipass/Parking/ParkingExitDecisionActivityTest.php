<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Jobs\ConfirmParkingPaymentJob;
use Kanvas\Connectors\Movipass\Parking\Actions\QuoteParkingExitAction;
use Kanvas\Connectors\Movipass\Parking\ParkingOperatorResolver;
use Kanvas\Connectors\Movipass\Parking\SimulatedParkingOperator;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingExitDecisionActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingPassScannedActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Passes\Services\PassScanDecisionGate;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Tests\Connectors\Support\ThrowingParkingOperator;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Event\Concerns\BuildsWalkInParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncRules;
use Workflow\Models\StoredWorkflow;

final class ParkingExitDecisionActivityTest extends TestCase
{
    use BuildsSyncRules;
    use BuildsWalkInParking;
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    private Carbon $entryAt;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce', 'workflow'];
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
        $this->entryAt = Carbon::now()->startOfSecond();

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

    public function testAllowedWhenPaidConfirmedAndAuthorized(): void
    {
        [$booking, $order] = $this->enteredAndQuoted();
        $this->payAndConfirm($order);

        $this->assertDecision($this->decide($booking), true);
    }

    public function testDeniedWhenTheQuoteIsUnpaid(): void
    {
        [$booking] = $this->enteredAndQuoted();

        $this->assertDecision($this->decide($booking), false);
    }

    public function testDeniedAndConfirmationRequestedWhenPaidButUnconfirmed(): void
    {
        [$booking, $order] = $this->enteredAndQuoted();
        $this->addPaidPayment($order);
        Bus::fake();

        $result = $this->decide($booking);

        $this->assertDecision($result, false);
        Bus::assertDispatched(ConfirmParkingPaymentJob::class);
        $this->assertTrue(ParkingQuotePayment::isPaid($order));
        $this->assertEmpty(ParkingMetadata::of($order->refresh())[ParkingMetadata::PAYMENT_CONFIRMED_AT] ?? null);
    }

    public function testDeniedWhenTheProviderDeniesTheExit(): void
    {
        [$booking, $order] = $this->enteredAndQuoted();
        $this->payAndConfirm($order);
        $this->gatedProduct->set(SimulatedParkingOperator::FORCED_OUTCOME_SETTING, SimulatedParkingOperator::DENY_EXIT);

        $this->assertDecision($this->decide($booking), false);
    }

    public function testDeniedWithoutThrowingWhenTheProviderThrows(): void
    {
        [$booking, $order] = $this->enteredAndQuoted();
        $this->payAndConfirm($order);
        $this->gatedProduct->set(ParkingOperatorResolver::PRODUCT_SETTING, ThrowingParkingOperator::class);

        $this->assertDecision($this->decide($booking), false);
    }

    public function testDeniedWhenTheBookingIsNotWalkIn(): void
    {
        [$booking, $order] = $this->enteredAndQuoted();
        $order->resources_type = null;
        $order->saveQuietly();

        $this->assertDecision($this->decide($booking), false);
    }

    public function testDeniedWhenTheBookingHasNoOrder(): void
    {
        [$booking] = $this->enteredAndQuoted();
        $booking->event->orders()->delete();

        $this->assertDecision($this->decide($booking), false);
    }

    public function testASyncRuleOnPassScanRequestedGatesTheExitScan(): void
    {
        $this->createSyncRule(
            app: $this->apps,
            event: WorkflowEnum::PASS_SCAN_REQUESTED->value,
            modelClass: EventVersion::class,
            activityClass: ParkingExitDecisionActivity::class,
            company: $this->company,
        );
        [$booking, $order] = $this->enteredAndQuoted();
        $code = (string) $booking->getPassCode();

        $this->assertSame(PassScanResultEnum::ADMITTED, $this->scan($code)->result);
        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $this->scan($code)->result);

        $this->addPaidPayment($order);
        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $this->scan($code)->result);

        new ConfirmParkingPaymentJob($order->app, $order)->handle();

        $this->assertSame(PassScanResultEnum::ADMITTED, $this->scan($code)->result);
    }

    public function testTheGateDeniesTheScanWhenTheMovipassIntegrationIsMissing(): void
    {
        $this->createSyncRule(
            app: $this->apps,
            event: WorkflowEnum::PASS_SCAN_REQUESTED->value,
            modelClass: EventVersion::class,
            activityClass: ParkingExitDecisionActivity::class,
            company: $this->company,
        );
        [$booking, $order] = $this->enteredAndQuoted();
        $this->payAndConfirm($order);
        $code = (string) $booking->getPassCode();
        $this->assertSame(PassScanResultEnum::ADMITTED, $this->scan($code)->result);
        $integrations = IntegrationsCompany::where('companies_id', $this->company->getId());
        EntityIntegrationHistory::whereIn('integrations_company_id', $integrations->pluck('id'))->delete();
        $integrations->delete();

        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $this->scan($code)->result);
    }

    private function assertDecision(array $result, bool $allowed): void
    {
        $this->assertArrayHasKey(PassScanDecisionGate::DECISION_KEY, $result, json_encode($result));
        $this->assertSame($allowed, $result[PassScanDecisionGate::DECISION_KEY]['allowed']);
        $this->assertNotEmpty($result[PassScanDecisionGate::DECISION_KEY]['reason']);
        $this->assertArrayNotHasKey('skipped', $result);
    }

    private function enteredAndQuoted(): array
    {
        $this->buildWalkInParking();
        $this->gatedVariant->variantWarehouses()->each(function ($row): void {
            $row->config = ['rates' => ['hourly' => 0.75]];
            $row->save();
        });
        $booking = $this->book($this->startedSlot(), '404040');
        $order = $booking->event->orders()->firstOrFail();

        Carbon::setTestNow($this->entryAt);
        new ParkingPassScannedActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute(
            $booking->refresh(),
            $this->apps,
            [
                'use_number' => 1,
                'max_uses' => 2,
                'scanned_at' => $this->entryAt->toIso8601String(),
            ]
        );

        Carbon::setTestNow($this->entryAt->copy()->addMinutes(60));
        new QuoteParkingExitAction($order->refresh())->execute();

        return [$booking->refresh(), $order->refresh()];
    }

    private function payAndConfirm(Order $order): void
    {
        $this->addPaidPayment($order);
        new ConfirmParkingPaymentJob($order->app, $order)->handle();
        $order->refresh();
        $this->assertNotEmpty(ParkingMetadata::of($order)[ParkingMetadata::PAYMENT_CONFIRMED_AT]);
    }

    private function decide(EventVersion $booking): array
    {
        $activity = new ParkingExitDecisionActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        return $activity->execute($booking->refresh(), $this->apps, []);
    }

    private function scan(string $code): PassScanOutcome
    {
        return new ScanEventPassAction($this->apps, $this->user, $this->company, $this->gatedProduct)->execute($code);
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
        $payment->amount = ParkingMetadata::of($order->refresh())[ParkingMetadata::QUOTE]['amount_due'];
        $payment->currency = 'USD';
        $payment->status = PaymentStatusEnum::PAID->value;
        $payment->processor = 'test';
        $payment->metadata = ['data' => []];
        $payment->saveOrFail();

        return $payment;
    }
}
