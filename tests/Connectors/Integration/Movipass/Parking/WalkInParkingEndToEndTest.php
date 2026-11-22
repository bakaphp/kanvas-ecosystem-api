<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\RecordParkingPassScanAction;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Jobs\ConfirmParkingPaymentJob;
use Kanvas\Connectors\Movipass\Parking\Actions\QuoteParkingExitAction;
use Kanvas\Connectors\Movipass\Parking\Enums\ParkingStateEnum;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingStateResolver;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingPassScannedActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Support\CataloguesParkingActivities;
use Tests\Connectors\Support\RecordsParkingPayments;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\Event\Concerns\BuildsWalkInParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;

final class WalkInParkingEndToEndTest extends TestCase
{
    use BuildsWalkInParking;
    use CataloguesParkingActivities;
    use DatabaseTransactions;
    use HasIntegrationCompany;
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

    public function testTheSpecTripFromBookingToCompletedExit(): void
    {
        $booking = $this->setUpAndBook();
        $code = (string) $booking->getPassCode();
        $order = $booking->event->orders()->firstOrFail();

        $this->assertSame('created', $order->orderStatus?->slug);
        $this->assertNull(ParkingMetadata::of($order)[ParkingMetadata::ENTRY_AT] ?? null);

        Carbon::setTestNow($this->entryAt);
        $this->assertSame(PassScanResultEnum::ADMITTED, $this->scan($code)->result);
        $this->runPassScanned($booking, useNumber: 1);

        $order->refresh();
        $this->assertSame('active', $order->orderStatus?->slug);
        $this->assertSame(
            $this->entryAt->toIso8601String(),
            Carbon::parse(ParkingMetadata::of($order)[ParkingMetadata::ENTRY_AT])->toIso8601String()
        );
        $this->assertSame(ParkingStateEnum::IN_PROGRESS, ParkingStateResolver::for($order));

        Carbon::setTestNow($this->entryAt->copy()->addHour());
        new QuoteParkingExitAction($order)->execute();
        $order->refresh();
        $this->assertSame(0.75, ParkingMetadata::of($order)[ParkingMetadata::QUOTE]['amount']);

        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $this->scan($code)->result);
        $this->assertSame(1, $this->useCount($booking));

        $this->addPaidPaymentForQuote($order);
        $this->assertTrue(ParkingQuotePayment::isPaid($order));
        new ConfirmParkingPaymentJob($order->app, $order)->handle();
        $order->refresh();
        $this->assertNotEmpty(ParkingMetadata::of($order)[ParkingMetadata::PAYMENT_CONFIRMED_AT]);
        $this->assertSame(ParkingStateEnum::EXIT_ENABLED, ParkingStateResolver::for($order));

        $this->assertSame(PassScanResultEnum::ADMITTED, $this->scan($code)->result);
        $this->assertSame(2, $this->useCount($booking));
        $this->runPassScanned($booking, useNumber: 2);

        $order->refresh();
        $parking = ParkingMetadata::of($order);
        $this->assertSame('completed', $order->orderStatus?->slug);
        $this->assertSame(
            $this->entryAt->toIso8601String(),
            Carbon::parse($parking[ParkingMetadata::ENTRY_AT])->toIso8601String()
        );
        $this->assertSame(
            $this->entryAt->copy()->addHour()->toIso8601String(),
            Carbon::parse($parking[RecordParkingPassScanAction::EXIT_AT])->toIso8601String()
        );
        $this->assertSame(0.75, (float) $order->total_net_amount);
        $this->assertSame(ParkingStateEnum::FINISHED, ParkingStateResolver::for($order));

        $this->assertSame(PassScanResultEnum::ALREADY_ADMITTED, $this->scan($code)->result);
    }

    public function testAnExitBeforePayingNeverOpensTheGate(): void
    {
        $booking = $this->setUpAndBook();
        $code = (string) $booking->getPassCode();
        $order = $booking->event->orders()->firstOrFail();

        Carbon::setTestNow($this->entryAt);
        $this->assertSame(PassScanResultEnum::ADMITTED, $this->scan($code)->result);
        $this->runPassScanned($booking, useNumber: 1);

        Carbon::setTestNow($this->entryAt->copy()->addHour());
        new QuoteParkingExitAction($order->refresh())->execute();

        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $this->scan($code)->result);
        $this->assertSame(1, $this->useCount($booking));
        $this->assertSame('active', $order->refresh()->orderStatus?->slug);
    }

    private function setUpAndBook(): EventVersion
    {
        $this->buildWalkInParking(walkIn: false);
        $this->configureOwnedWalkInOrderType();

        $exitCode = Artisan::call('kanvas-movipass:setup-walk-in-parking', [
            'app_id' => $this->apps->getId(),
            'product_id' => $this->gatedProduct->getId(),
            '--hourly' => 0.75,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        return $this->book($this->startedSlot(), '404040');
    }

    private function scan(string $code): PassScanOutcome
    {
        return new ScanEventPassAction(
            $this->apps,
            $this->user,
            $this->company,
            $this->gatedProduct
        )->execute($code);
    }

    private function runPassScanned(EventVersion $booking, int $useNumber): void
    {
        new ParkingPassScannedActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute(
            $booking->refresh(),
            $this->apps,
            [
                'use_number' => $useNumber,
                'max_uses' => 2,
                'scanned_at' => now()->toIso8601String(),
                'products_id' => $this->gatedProduct->getId(),
            ]
        );
    }

    private function useCount(EventVersion $booking): int
    {
        return (int) ParticipantPass::query()
            ->where('event_version_id', $booking->getId())
            ->whereNull('participant_id')
            ->value('use_count');
    }
}
