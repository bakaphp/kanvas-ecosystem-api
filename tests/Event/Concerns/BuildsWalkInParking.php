<?php

declare(strict_types=1);

namespace Tests\Event\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Workflows\Activities\ParkingPassScannedActivity;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Tests\Connectors\Support\ConfiguresParkingRates;
use Workflow\Models\StoredWorkflow;

trait BuildsWalkInParking
{
    use BuildsInviteOnlyParking;
    use ConfiguresParkingRates;

    private function prepareWalkInContext(): void
    {
        Notification::fake();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);
        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();
    }

    private function buildWalkInParking(array $config = [], bool $walkIn = true): void
    {
        $this->ensureWarehouseAndChannel();
        $attributes = $walkIn ? [['name' => WalkInAccess::ATTRIBUTE_NAME, 'value' => json_encode($config)]] : [];
        $productResponse = $this->graphQLData($this->createProduct(attributes: $attributes), 'createProduct');

        $this->gatedProduct = Products::find($productResponse['id']);
        $this->gatedVariant = $this->gatedProduct->variants()->first();
        $this->makeVariantSellable($this->gatedVariant);
        $this->configureBookingOrderType();
    }

    private function configureOwnedWalkInOrderType(?array $config = null): OrderTypes
    {
        $orderType = $this->configureBookingOrderType('movipass');
        $orderType->companies_id = $this->company->getId();
        $orderType->config = $config;
        $orderType->saveOrFail();

        return $orderType;
    }

    private function startedSlot(): TimeSlots
    {
        $slot = $this->makeDaySlot($this->gatedVariant, Carbon::now()->subHour(), 5);
        $slot->update(['end_at' => Carbon::now()->addHours(5)]);

        return $slot;
    }

    private function bookRequest(
        TimeSlots $slot,
        ?string $vehicle,
        string $selection = 'id',
        array $metadata = []
    ): TestResponse {
        return $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { ' . $selection . ' }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $slot->id,
                'metadata' => [
                    'category_id' => EventCategory::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                    'type_id' => EventType::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                    ...($vehicle === null ? [] : ['variants_ids' => ['vehicle_variant_id' => $vehicle]]),
                    ...$metadata,
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }

    private function book(TimeSlots $slot, string $vehicle, array $metadata = []): EventVersion
    {
        $response = $this->bookRequest($slot, $vehicle, metadata: $metadata);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
    }

    private function bookWalkIn(array $config = [], string $vehicle = '404040', ?float $hourlyRate = null): array
    {
        $this->buildWalkInParking($config);

        if ($hourlyRate !== null) {
            $this->setHourlyRate($this->gatedVariant, $hourlyRate);
        }

        $booking = $this->book($this->startedSlot(), $vehicle);

        return [$booking, $booking->event->orders()->firstOrFail()];
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

    private function runParkingPassScanned(
        EventVersion $booking,
        int $useNumber,
        string $scannedAt,
        int $maxUses = 2,
        array $extraParams = []
    ): array {
        $activity = new ParkingPassScannedActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        return $activity->execute(
            $booking->refresh(),
            $this->apps,
            [
                'use_number' => $useNumber,
                'max_uses' => $maxUses,
                'scanned_at' => $scannedAt,
                ...$extraParams,
            ]
        );
    }
}
