<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Kanvas\Connectors\Movipass\Jobs\ConfirmParkingPaymentJob;
use Kanvas\Connectors\Movipass\Parking\Actions\QuoteParkingExitAction;
use Kanvas\Connectors\Movipass\Parking\ParkingOperatorResolver;
use Kanvas\Connectors\Movipass\Parking\SimulatedParkingOperator;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingExitDecisionActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Passes\Services\PassScanDecisionGate;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Tests\Connectors\Support\PreparesMovipassParking;
use Tests\Connectors\Support\RecordsParkingPayments;
use Tests\Connectors\Support\ThrowingParkingOperator;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncRules;
use Workflow\Models\StoredWorkflow;

final class ParkingExitDecisionActivityTest extends TestCase
{
    use BuildsSyncRules;
    use PreparesMovipassParking;
    use DatabaseTransactions;
    use InventoryCases;
    use RecordsParkingPayments;

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

        $this->prepareMovipassParking();
        $this->entryAt = Carbon::now()->startOfSecond();
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
        $this->addPaidPaymentForQuote($order);
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

        $this->addPaidPaymentForQuote($order);
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
        [$booking, $order] = $this->bookWalkIn(hourlyRate: 0.75);

        Carbon::setTestNow($this->entryAt);
        $this->runParkingPassScanned($booking, 1, $this->entryAt->toIso8601String());

        Carbon::setTestNow($this->entryAt->copy()->addMinutes(60));
        new QuoteParkingExitAction($order->refresh())->execute();

        return [$booking->refresh(), $order->refresh()];
    }

    private function payAndConfirm(Order $order): void
    {
        $this->addPaidPaymentForQuote($order);
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
}
