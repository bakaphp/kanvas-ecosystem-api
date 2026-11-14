<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\ActivateEventBookingOrderAction;
use Kanvas\Event\Events\Actions\BuildEventDataAction;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use RuntimeException;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class InviteBookingOrderTest extends TestCase
{
    use BuildsInviteOnlyParking;
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected array $days;
    protected OrderTypes $orderType;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce'];
    }

    public function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);
        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        new Setup($this->apps, $this->user, $this->company)->run();

        $this->days = $this->buildInviteOnlyParking(days: 2, capacity: 5);
        $this->orderType = $this->configureBookingOrderType();
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('invite-code:user:' . $this->user->getId());

        parent::tearDown();
    }

    public function testInviteBookingCreatesAFreeOrderOfTheConfiguredType(): void
    {
        $booking = $this->bookDay(0, ['arrival_hour' => '18:00', 'vehiclePlate' => 'A123456']);

        $order = $this->orderOf($booking);

        $this->assertSame($this->orderType->name, $order->orderType->name);
        $this->assertEquals(0, $order->total_gross_amount);
        $this->assertEquals(0, $order->items->first()->unit_price_gross_amount);
        $this->assertTrue($order->isEventBacked());
        $this->assertSame('created', $order->orderStatus->slug);
        $this->assertSame($this->days[0]['slot']->start_at->toDateTimeString(), $order->metadata['data']['start_at']);
        $this->assertSame($this->days[0]['slot']->end_at->toDateTimeString(), $order->metadata['data']['end_at']);
        $this->assertSame('18:00', $order->metadata['data']['arrival_hour']);
        $this->assertSame('A123456', $order->metadata['data']['vehiclePlate']);
    }

    public function testThePlateIsOptional(): void
    {
        $order = $this->orderOf($this->bookDay(0));

        $this->assertArrayNotHasKey('vehiclePlate', $order->metadata['data']);
    }

    public function testTheOrderNeverCarriesTheInviteCode(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $booking = EventVersion::findOrFail($this->bookWithInvite($this->days[0]['slot'], $code)->json('data.bookTimeSlot.id'));

        $this->assertStringNotContainsString($code, json_encode($this->orderOf($booking)->toArray()));
    }

    public function testPassesAreIssuedAtBookingWithoutWaitingForPayment(): void
    {
        $booking = $this->bookDay(0);

        $this->assertSame(2, ParticipantPass::where('event_version_id', $booking->getId())->count());
    }

    public function testSoukVariantCapacityDoesNotBlockEventBackedBookings(): void
    {
        $this->orderType->config = [
            ...$this->orderType->config,
            'expirable' => true,
        ];
        $this->orderType->saveOrFail();
        $variantWarehouse = $this->gatedVariant->variantWarehouses()->firstOrFail();
        $variantWarehouse->max_capacity = 1;
        $variantWarehouse->save();

        $first = $this->bookDay(0);
        $second = $this->bookDay(0);

        $this->assertNotNull($this->orderOf($first));
        $this->assertNotNull($this->orderOf($second));
    }

    public function testCancellingTheBookingCancelsTheLinkedOrder(): void
    {
        $booking = $this->bookDay(0);

        $response = $this->graphQL('
            mutation cancel($id: ID!) {
                cancelTimeSlotBooking(id: $id) { id }
            }
        ', ['id' => (string) $booking->getId()], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $order = $this->orderOf($booking);
        $this->assertSame('cancelled', $order->orderStatus->slug);
        $this->assertSame('canceled', $order->status);
        $this->assertNull($this->redeemedBy($booking));
    }

    public function testALateFailureCancelsTheOrderItCreated(): void
    {
        $slot = $this->days[0]['slot'];
        $input = [
            'start_at' => $slot->start_at->toDateTimeString(),
            'end_at' => $slot->end_at->toDateTimeString(),
            'time_slot_id' => $slot->id,
            'participants' => [[
                'firstname' => 'Late',
                'lastname' => 'Failure',
                'contacts' => [['contacts_types_id' => 1, 'value' => 'late.failure@example.com', 'weight' => 1]],
            ]],
        ];
        $eventData = new BuildEventDataAction($slot->resource, $this->user, $this->company, $input)->execute();
        $action = new class (EventDto::from($this->apps, $this->user, $this->company, $eventData), ['slug_suffix' => uniqid()]) extends CreateEventAction {
            protected function createEventInTransaction(): Event
            {
                parent::createEventInTransaction();

                throw new RuntimeException('late failure');
            }
        };
        $action->claimingBookingWith(fn () => null);

        try {
            $action->execute();
            $this->fail('The late failure should have bubbled up.');
        } catch (RuntimeException $exception) {
            $this->assertSame('late failure', $exception->getMessage());
        }

        $order = Order::query()
            ->where('apps_id', $this->apps->getId())
            ->where('order_types_id', $this->orderType->getId())
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('canceled', $order->status);
        $this->assertSame('cancelled', $order->orderStatus->slug);
    }

    public function testTheBookingOfAnOvernightSlotEndsWithTheSlotAndItsPassSurvivesMidnight(): void
    {
        $booking = $this->bookDay(0);

        $this->assertSame($this->days[0]['slot']->end_at->toDateTimeString(), $booking->refresh()->end_at->toDateTimeString());
        $expiration = ParticipantPass::where('event_version_id', $booking->getId())->value('expiration_date');
        $this->assertSame($this->days[0]['slot']->end_at->toDateString(), Carbon::parse($expiration)->toDateString());
    }

    public function testActivationMovesAFreeEventBackedOrderToActive(): void
    {
        $order = $this->orderOf($this->bookDay(0));

        $activated = new ActivateEventBookingOrderAction($order)->execute();

        $this->assertTrue($activated);
        $this->assertSame('active', $order->refresh()->orderStatus->slug);
    }

    public function testActivationIgnoresOrdersThatAreNotEventBacked(): void
    {
        $order = $this->orderOf($this->bookDay(0));
        $order->resources_type = null;
        $order->saveQuietly();

        $this->assertFalse(new ActivateEventBookingOrderAction($order)->execute());
        $this->assertSame('created', $order->refresh()->orderStatus->slug);
    }

    public function testBookingsOfAProductTypeWithoutConfigurationKeepTheDefaultOrder(): void
    {
        $plainResponse = $this->graphQLData($this->createProduct(), 'createProduct');
        $plainVariant = Products::find($plainResponse['id'])->variants()->first();
        $this->makeVariantSellable($plainVariant);
        $slot = $this->makeDaySlot($plainVariant, now()->addDays(3), 3);

        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $slot->id,
                'participants' => [[
                    'firstname' => 'Plain',
                    'lastname' => 'Guest',
                    'contacts' => [['contacts_types_id' => 1, 'value' => 'plain.order@example.com', 'weight' => 1]],
                ]],
                'metadata' => ['create_order' => '1'],
                'order_items' => [[
                    'variant_id' => (string) $plainVariant->getId(),
                    'name' => 'Plain booking',
                    'quantity' => 1,
                    'price' => 10,
                ]],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $order = $this->orderOf(EventVersion::findOrFail($response->json('data.bookTimeSlot.id')));
        $this->assertSame('event', $order->orderType->name);
        $this->assertSame('completed', $order->status);
        $this->assertEmpty($order->metadata);
        $this->assertSame(0, ParticipantPass::where('event_version_id', $response->json('data.bookTimeSlot.id'))->count());
    }

    private function bookDay(int $day, array $metadata = []): EventVersion
    {
        $code = $this->issueInvite($this->days[$day]['version']);
        $response = $this->bookWithInvite($this->days[$day]['slot'], $code, $metadata);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));

        return EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
    }

    private function orderOf(EventVersion $booking): Order
    {
        return $booking->event->orders()->firstOrFail();
    }
}
