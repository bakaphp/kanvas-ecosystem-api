<?php

declare(strict_types=1);

namespace Tests\Event\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\OrderTypes;

trait BuildsWalkInParking
{
    use BuildsInviteOnlyParking;

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
}
