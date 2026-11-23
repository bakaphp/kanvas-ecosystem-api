<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\Parking;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Kanvas\Connectors\Movipass\Jobs\ConfirmParkingPaymentJob;
use Kanvas\Connectors\Movipass\Parking\Actions\QuoteParkingExitAction;
use Kanvas\Connectors\Movipass\Parking\Enums\ParkingStateEnum;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingStateResolver;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Tests\Connectors\Support\CataloguesParkingActivities;
use Tests\Connectors\Support\PreparesMovipassParking;
use Tests\Connectors\Support\RecordsParkingPayments;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class WalkInParkingEndToEndTest extends TestCase
{
    use PreparesMovipassParking;
    use CataloguesParkingActivities;
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
            Carbon::parse($parking[ParkingMetadata::EXIT_AT])->toIso8601String()
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

    private function runPassScanned(EventVersion $booking, int $useNumber): void
    {
        $this->runParkingPassScanned(
            $booking,
            $useNumber,
            now()->toIso8601String(),
            extraParams: ['products_id' => $this->gatedProduct->getId()]
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
