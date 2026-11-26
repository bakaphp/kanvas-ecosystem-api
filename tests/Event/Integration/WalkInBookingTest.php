<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Actions\ActivateEventBookingOrderAction;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Souk\Orders\Models\Order;
use Tests\Event\Concerns\BuildsWalkInParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class WalkInBookingTest extends TestCase
{
    use BuildsWalkInParking;
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce'];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->prepareWalkInContext();
    }

    public function testWalkInConfigDefaultsAndOverrides(): void
    {
        $this->buildWalkInParking();
        $defaults = WalkInAccess::forResource($this->gatedVariant);

        $this->assertSame([2, 2, 30], [$defaults->maxUses, $defaults->decisionFromUse, $defaults->expireUnusedMinutes]);

        $this->buildWalkInParking(['max_uses' => 3, 'expire_unused_minutes' => 10]);
        $custom = WalkInAccess::forResource($this->gatedVariant);

        $this->assertSame([3, 2, 10], [$custom->maxUses, $custom->decisionFromUse, $custom->expireUnusedMinutes]);
    }

    public function testASlotThatAlreadyStartedIsBookedWithAPassAndAnOrderWithoutEndAt(): void
    {
        $this->buildWalkInParking();

        $booking = $this->book($this->startedSlot(), '111111');

        $order = $booking->event->orders()->firstOrFail();
        $pass = ParticipantPass::where('event_version_id', $booking->getId())->whereNull('participant_id')->firstOrFail();

        $this->assertNotNull($pass->code);
        $this->assertSame('QR', strtoupper($pass->format));
        $this->assertSame(2, (int) $pass->max_uses);
        $this->assertSame(['decision_from_use' => 2], json_decode($pass->payload, true));
        $this->assertSame('created', $order->orderStatus->slug);
        $this->assertEquals(0, $order->total_gross_amount);
        $this->assertArrayNotHasKey('end_at', $order->metadata['data']);
        $this->assertArrayHasKey('start_at', $order->metadata['data']);
    }

    public function testTheBookingReturnsThePassCode(): void
    {
        $this->buildWalkInParking();

        $response = $this->bookRequest($this->startedSlot(), '222222', 'id pass_code');

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertNotEmpty($response->json('data.bookTimeSlot.pass_code'));
    }

    public function testAnEndedSlotIsRejected(): void
    {
        $this->buildWalkInParking();
        $slot = $this->startedSlot();
        $slot->update(['end_at' => Carbon::now()->subMinute()]);

        $response = $this->bookRequest($slot, '333333');

        $this->assertStringContainsString('ended', (string) $response->json('errors.0.message'));
    }

    public function testASecondOpenVisitForTheSameVehicleIsRejected(): void
    {
        $this->buildWalkInParking();
        $slot = $this->startedSlot();
        $this->book($slot, '444444');

        $response = $this->bookRequest($slot, '444444');

        $this->assertStringContainsString('open visit', (string) $response->json('errors.0.message'));
        $this->assertNotNull($this->book($slot, '555555'));
    }

    public function testAVehicleCanBookAgainOnceThePreviousVisitIsCancelled(): void
    {
        $this->buildWalkInParking();
        $slot = $this->startedSlot();
        $first = $this->book($slot, '777777');

        new CancelEventAction($first)->execute();

        $this->assertNotNull($this->book($slot, '777777'));
    }

    public function testAVehicleCanBookAgainOnceThePreviousOrderIsCompleted(): void
    {
        $this->buildWalkInParking();
        $slot = $this->startedSlot();
        $first = $this->book($slot, '888888');
        $order = $first->event->orders()->firstOrFail();
        $order->order_status_id = $order->orderType->statuses()->where('slug', 'completed')->firstOrFail()->getId();
        $order->saveQuietly();

        $this->assertNotNull($this->book($slot, '888888'));
    }

    public function testASecondOpenVisitOnADifferentSlotIsRejected(): void
    {
        $this->buildWalkInParking();
        $this->book($this->startedSlot(), '121212');

        $response = $this->bookRequest($this->startedSlot(hoursAgo: 2), '121212');

        $this->assertStringContainsString('open visit', (string) $response->json('errors.0.message'));
    }

    public function testABookingWithoutAnOrderIsNotAnOpenVisit(): void
    {
        $this->buildWalkInParking();
        $slot = $this->startedSlot();
        $first = $this->book($slot, '131313');
        $first->event->orders()->delete();

        $this->assertNotNull($this->book($slot, '131313'));
    }

    public function testAVehicleIsRequired(): void
    {
        $this->buildWalkInParking();

        $response = $this->bookRequest($this->startedSlot(), null);

        $this->assertStringContainsString('vehicle is required', (string) $response->json('errors.0.message'));
    }

    public function testAWalkInOrderStaysCreated(): void
    {
        $this->buildWalkInParking();
        $order = $this->book($this->startedSlot(), '999999')->event->orders()->firstOrFail();

        $activated = new ActivateEventBookingOrderAction($order)->execute();

        $this->assertFalse($activated);
        $this->assertSame('created', Order::find($order->getId())->orderStatus->slug);
    }

    public function testANonWalkInResourceStillRejectsAStartedSlot(): void
    {
        $this->buildWalkInParking(walkIn: false);

        $response = $this->bookRequest($this->startedSlot(), '101010');

        $this->assertStringContainsString('already started', (string) $response->json('errors.0.message'));
    }
}
