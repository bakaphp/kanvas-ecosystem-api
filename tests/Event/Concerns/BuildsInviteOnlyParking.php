<?php

declare(strict_types=1);

namespace Tests\Event\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductTypeDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\CreateOrderStatusesAction;
use Kanvas\Souk\Orders\Models\OrderTypes;

trait BuildsInviteOnlyParking
{
    use CreatesEventVersions;

    protected Event $gatedEvent;
    protected Products $gatedProduct;
    protected Variants $gatedVariant;
    protected ?array $gatedWarehouse = null;
    protected ?array $gatedChannel = null;

    private function buildInviteOnlyParking(int $days = 2, int $capacity = 5): array
    {
        $this->gatedEvent = $this->createEvent();
        $firstDay = Carbon::now()->addDays(10)->setTime(15, 0);
        $slots = [];

        foreach (range(0, $days - 1) as $offset) {
            $start = $firstDay->copy()->addDays($offset);
            $version = $this->createEventVersion($this->gatedEvent, startAt: $start);
            $slots[$offset] = ['version' => $version, 'slot' => null, 'start' => $start];
        }

        $this->buildGatedProduct($this->gatedEvent);

        foreach ($slots as $offset => $day) {
            $slots[$offset]['slot'] = $this->makeDaySlot($this->gatedVariant, $day['start'], $capacity);
        }

        return $slots;
    }

    private function buildGatedProduct(Event $event, ?array $access = null): void
    {
        $access ??= ['event_id' => $event->getId()];
        $this->ensureWarehouseAndChannel();
        $productResponse = $this->graphQLData(
            $this->createProduct(attributes: [['name' => 'event_access', 'value' => json_encode($access)]]),
            'createProduct'
        );

        $this->gatedProduct = Products::find($productResponse['id']);
        $this->gatedVariant = $this->gatedProduct->variants()->first();
        $this->makeVariantSellable($this->gatedVariant);
    }

    private function configureBookingOrderType(string $name = 'event-parking'): OrderTypes
    {
        $productType = new CreateProductTypeAction(
            new ProductTypeDto(
                company: $this->company,
                user: $this->user,
                name: 'Event parking',
                slug: $name,
            ),
            $this->user,
        )->execute();
        $this->gatedProduct->update(['products_types_id' => $productType->getId()]);

        new CreateOrderStatusesAction($this->apps, $name, [
            'created' => ['is_default' => true, 'transitions' => ['active', 'cancelled']],
            'active' => ['transitions' => ['completed', 'cancelled']],
            'completed' => ['is_final' => true],
            'cancelled' => ['is_final' => true],
        ])->execute();

        $orderType = OrderTypes::where('apps_id', $this->apps->getId())->where('name', $name)->firstOrFail();
        $orderType->config = [OrderTypes::EVENT_BOOKING => ['product_types' => [$name]]];
        $orderType->saveOrFail();

        return $orderType;
    }

    private function ensureWarehouseAndChannel(): void
    {
        $this->gatedWarehouse ??= $this->graphQLData($this->createWarehouses((string) $this->region->getId()), 'createWarehouse');
        $this->gatedChannel ??= $this->graphQLData($this->createChannel(), 'createChannel');
    }

    private function makeVariantSellable(Variants $variant): void
    {
        $this->ensureWarehouseAndChannel();
        $warehouse = $this->gatedWarehouse;
        $channel = $this->gatedChannel;

        $this->graphQLData(
            $this->addVariantToChannel(
                variantId: (string) $variant->getId(),
                channelId: $channel['id'],
                warehouseData: ['id' => $warehouse['id']]
            ),
            'addVariantToChannel'
        );

        $this->graphQLData(
            $this->addVariantToWarehouse(
                variantId: (string) $variant->getId(),
                warehouseId: (string) $warehouse['id'],
                amount: 10
            ),
            'addVariantToWarehouse'
        );
    }

    private function makeDaySlot(Variants $variant, Carbon $start, int $capacity): TimeSlots
    {
        return TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $variant->getId(),
            'resources_type' => $variant->getMorphClass(),
            'start_at' => $start,
            'end_at' => $start->copy()->addHours(11),
            'initial_capacity' => $capacity,
            'status' => 'open',
        ]);
    }

    private function issueInvite(EventVersion $dayVersion): string
    {
        [, $code] = new CreatePassAction(
            event: $dayVersion->event,
            eventVersion: $dayVersion,
            expirationDate: Carbon::now()->addDays(30),
            format: PassFormatEnum::QR_CODE
        )->execute();

        return $code;
    }

    private function issueEventCode(int $uses = 1): string
    {
        [, $code] = new CreatePassAction(
            event: $this->gatedEvent,
            eventVersion: $this->gatedEvent->versions()->orderBy('start_at')->firstOrFail(),
            expirationDate: Carbon::now()->addDays(30),
            format: PassFormatEnum::QR_CODE,
            maxUses: $uses,
            eventWide: true
        )->execute();

        return $code;
    }

    private function bookWithInvite(TimeSlots $timeSlot, ?string $code, array $metadata = []): TestResponse
    {
        return $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => array_filter([
                'time_slot_id' => (string) $timeSlot->id,
                'invite_code' => $code,
                'metadata' => [
                    'category_id' => EventCategory::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                    'type_id' => EventType::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                    ...$metadata,
                ],
            ], fn (mixed $value) => $value !== null),
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }

    private function bookInviteOnDay(array $day, array $metadata = []): EventVersion
    {
        $response = $this->bookWithInvite($day['slot'], $this->issueInvite($day['version']), $metadata);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('invite-code:ip:127.0.0.1');

        return EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
    }

    private function redeemedBy(EventVersion $booking): ?ParticipantPass
    {
        return ParticipantPass::query()->redeemedByBooking($booking->getId())->whereNotNull('redeemed_at')->first();
    }
}
