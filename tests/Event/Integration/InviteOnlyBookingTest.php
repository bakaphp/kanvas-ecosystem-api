<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Services\PassAttemptLimiter;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Tests\Event\Concerns\BuildsInviteOnlyFestival;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class InviteOnlyBookingTest extends TestCase
{
    use BuildsInviteOnlyFestival;
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected array $days;

    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
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

        $this->days = $this->buildFestival(days: 2, capacity: 2);
        $this->flushAttempts();
    }

    protected function tearDown(): void
    {
        $this->flushAttempts();

        parent::tearDown();
    }

    public function testGatedBookingWithoutACodeIsRejected(): void
    {
        $response = $this->bookWithInvite($this->days[0]['slot'], null);

        $this->assertStringContainsString('invite code is required', (string) $response->json('errors.0.message'));
        $this->assertSame(0, EventVersion::where('time_slot_id', $this->days[0]['slot']->id)->count());
    }

    public function testValidCodeBooksItsDayAndRedeemsTheCodeForTheBooking(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);

        $response = $this->bookWithInvite($this->days[0]['slot'], $code, ['arrival_hour' => '18:00', 'vehiclePlate' => 'A123456']);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $booking = EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
        $this->assertSame($this->days[0]['slot']->id, (int) $booking->time_slot_id);
        $this->assertSame(1, (int) $booking->total_attendees);
        $this->assertSame(1, $this->days[0]['slot']->refresh()->getBookedSlotsCount());
        $this->assertNotNull($this->redeemedBy($booking));
        $this->assertSame('18:00', $booking->metadata['arrival_hour']);
        $this->assertSame('A123456', $booking->metadata['vehiclePlate']);
    }

    public function testThePlainCodeIsNeverStoredOnTheBooking(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);

        $response = $this->bookWithInvite($this->days[0]['slot'], $code, ['invite_code' => $code]);

        $booking = EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
        $this->assertStringNotContainsString($code, json_encode($booking->metadata));
        $this->assertStringNotContainsString($code, json_encode($booking->event->getAttributes()));
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $this->bookWithInvite($this->days[0]['slot'], $code);

        $second = $this->bookWithInvite($this->days[0]['slot'], $code);

        $this->assertStringContainsString('Invalid access code', (string) $second->json('errors.0.message'));
        $this->assertSame(1, EventVersion::where('time_slot_id', $this->days[0]['slot']->id)->count());
    }

    public function testACodeOnlyBooksItsOwnDay(): void
    {
        $secondDayCode = $this->issueInvite($this->days[1]['version']);

        $response = $this->bookWithInvite($this->days[0]['slot'], $secondDayCode);

        $this->assertStringContainsString('Invalid access code', (string) $response->json('errors.0.message'));
        $this->assertSame(0, EventVersion::where('time_slot_id', $this->days[0]['slot']->id)->count());
        $this->assertNull(ParticipantPass::where('event_version_id', $this->days[1]['version']->getId())->first()->redeemed_at);
    }

    public function testAGuestInvitedToBothDaysBooksEachDayWithItsOwnCode(): void
    {
        $firstCode = $this->issueInvite($this->days[0]['version']);
        $secondCode = $this->issueInvite($this->days[1]['version']);

        $first = $this->bookWithInvite($this->days[0]['slot'], $firstCode);
        $second = $this->bookWithInvite($this->days[1]['slot'], $secondCode);

        $this->assertNull($first->json('errors'), json_encode($first->json('errors')));
        $this->assertNull($second->json('errors'), json_encode($second->json('errors')));
    }

    public function testACodeOfAnotherEventIsRejected(): void
    {
        $otherEvent = $this->createEvent();
        $otherVersion = $this->createEventVersion($otherEvent, startAt: $this->days[0]['start']->copy());
        $foreignCode = $this->issueInvite($otherVersion);

        $response = $this->bookWithInvite($this->days[0]['slot'], $foreignCode);

        $this->assertStringContainsString('Invalid access code', (string) $response->json('errors.0.message'));
    }

    public function testUnknownCodesAreRateLimitedAfterFiveFailures(): void
    {
        foreach (range(1, PassAttemptLimiter::MAX_FAILURES) as $attempt) {
            $response = $this->bookWithInvite($this->days[0]['slot'], 'WRONG' . $attempt);
            $this->assertStringContainsString('Invalid access code', (string) $response->json('errors.0.message'));
        }

        $validCode = $this->issueInvite($this->days[0]['version']);
        $blocked = $this->bookWithInvite($this->days[0]['slot'], $validCode);

        $this->assertStringContainsString('Too many invalid attempts', (string) $blocked->json('errors.0.message'));
        $this->assertNull(ParticipantPass::where('event_version_id', $this->days[0]['version']->getId())->first()->redeemed_at);
    }

    public function testFullSlotRejectsTheBookingWithoutConsumingTheCode(): void
    {
        $this->days[0]['slot']->update(['initial_capacity' => 1]);
        $firstCode = $this->issueInvite($this->days[0]['version']);
        $lateCode = $this->issueInvite($this->days[0]['version']);
        $this->bookWithInvite($this->days[0]['slot'], $firstCode);

        $response = $this->bookWithInvite($this->days[0]['slot'], $lateCode);

        $this->assertStringContainsString('enough available capacity', (string) $response->json('errors.0.message'));
        $latePass = ParticipantPass::where('event_version_id', $this->days[0]['version']->getId())
            ->whereNull('redeemed_at')
            ->first();
        $this->assertNotNull($latePass);
    }

    public function testCancellingTheBookingReleasesTheCodeAndTheCapacity(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $bookingId = $this->bookWithInvite($this->days[0]['slot'], $code)->json('data.bookTimeSlot.id');
        $booking = EventVersion::findOrFail($bookingId);

        new CancelEventAction($booking)->withoutNotifications()->disableWorkflow()->execute();

        $this->assertNull($this->redeemedBy($booking));
        $this->assertSame(0, $this->days[0]['slot']->refresh()->getBookedSlotsCount());

        $rebook = $this->bookWithInvite($this->days[0]['slot'], $code);
        $this->assertNull($rebook->json('errors'), json_encode($rebook->json('errors')));
    }

    public function testCancelMutationReleasesTheCodeForItsOwner(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $bookingId = $this->bookWithInvite($this->days[0]['slot'], $code)->json('data.bookTimeSlot.id');

        $response = $this->cancelBooking($bookingId);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertNull($this->redeemedBy(EventVersion::findOrFail($bookingId)));
        $this->assertSame(0, $this->days[0]['slot']->refresh()->getBookedSlotsCount());
    }

    public function testAnInviteBookingCannotBeMovedToAnotherSlot(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $bookingId = $this->bookWithInvite($this->days[0]['slot'], $code)->json('data.bookTimeSlot.id');

        $response = $this->graphQL('
            mutation update($input: TimeSlotBookingUpdateInput!) {
                updateTimeSlotBooking(input: $input) { id }
            }
        ', [
            'input' => [
                'event_version_id' => (string) $bookingId,
                'new_time_slot_id' => (string) $this->days[1]['slot']->id,
            ],
        ]);

        $this->assertStringContainsString('cannot be moved', (string) $response->json('errors.0.message'));
    }

    public function testVerifyReportsTheDayOfAValidCodeWithoutConsumingIt(): void
    {
        $code = $this->issueInvite($this->days[1]['version']);

        $response = $this->verifyInviteCode($code);

        $this->assertTrue($response->json('data.verifyInviteCode.valid'));
        $this->assertSame((string) $this->days[1]['slot']->id, $response->json('data.verifyInviteCode.time_slot.id'));
        $this->assertSame(2, $response->json('data.verifyInviteCode.time_slot.available_slots'));
        $this->assertNull(ParticipantPass::where('event_version_id', $this->days[1]['version']->getId())->first()->redeemed_at);

        $book = $this->bookWithInvite($this->days[1]['slot'], $code);
        $this->assertNull($book->json('errors'), json_encode($book->json('errors')));
    }

    public function testVerifyAnswersInvalidWithoutSayingWhy(): void
    {
        $usedCode = $this->issueInvite($this->days[0]['version']);
        $this->bookWithInvite($this->days[0]['slot'], $usedCode);

        $unknown = $this->verifyInviteCode('NOSUCHCODE');
        $used = $this->verifyInviteCode($usedCode);

        $this->assertFalse($unknown->json('data.verifyInviteCode.valid'));
        $this->assertFalse($used->json('data.verifyInviteCode.valid'));
        $this->assertNull($used->json('data.verifyInviteCode.time_slot'));
    }

    public function testVerifyFailuresShareTheBookingRateLimit(): void
    {
        foreach (range(1, PassAttemptLimiter::MAX_FAILURES) as $attempt) {
            $this->verifyInviteCode('WRONG' . $attempt);
        }

        $blocked = $this->verifyInviteCode('ANYTHING');

        $this->assertStringContainsString('Too many invalid attempts', (string) $blocked->json('errors.0.message'));
    }

    public function testVerifyOnAnUngatedProductIsInvalid(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $plainProduct = $this->graphQLData($this->createProduct(), 'createProduct');

        $response = $this->verifyInviteCode($code, $plainProduct['id']);

        $this->assertFalse($response->json('data.verifyInviteCode.valid'));
    }

    public function testUngatedSlotsKeepTheirBookingBehaviour(): void
    {
        $plainProduct = $this->graphQLData($this->createProduct(), 'createProduct');
        $plainVariant = \Kanvas\Inventory\Products\Models\Products::find($plainProduct['id'])->variants()->first();
        $plainSlot = $this->makeFestivalSlot($plainVariant, now()->addDays(3), 2);

        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id }
            }
        ', [
            'input' => [
                'time_slot_id' => (string) $plainSlot->id,
                'participants' => [[
                    'firstname' => 'Plain',
                    'lastname' => 'Guest',
                    'contacts' => [['contacts_types_id' => 1, 'value' => 'plain.guest@example.com', 'weight' => 1]],
                ]],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(1, $plainSlot->refresh()->getBookedSlotsCount());
    }

    public function testMalformedAccessConfigurationLocksTheProduct(): void
    {
        $this->buildGatedProduct($this->festival, ['unexpected' => true]);
        $slot = $this->makeFestivalSlot($this->gatedVariant, now()->addDays(4), 2);
        $code = $this->issueInvite($this->days[0]['version']);

        $response = $this->bookWithInvite($slot, $code);

        $this->assertStringContainsString('Invalid access code', (string) $response->json('errors.0.message'));
    }

    public function testGatedSlotStaysBookableAfterItsStartUntilItEnds(): void
    {
        $startedSlot = TimeSlots::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $this->gatedVariant->getId(),
            'resources_type' => $this->gatedVariant->getMorphClass(),
            'start_at' => now()->subHour(),
            'end_at' => now()->addHours(8),
            'initial_capacity' => 2,
            'status' => 'open',
        ]);
        $version = $this->createEventVersion($this->festival, startAt: now()->subHour());
        $code = $this->issueInvite($version);

        $response = $this->bookWithInvite($startedSlot, $code);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
    }

    public function testBookingLockStillGuardsGatedSlots(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $lock = Cache::lock('event:time-slot-booking:' . $this->days[0]['slot']->id, 30);
        $this->assertTrue($lock->get());

        try {
            $response = $this->bookWithInvite($this->days[0]['slot'], $code);
        } finally {
            $lock->release();
        }

        $this->assertStringContainsString('being booked by someone else', (string) $response->json('errors.0.message'));
        $this->assertNull(ParticipantPass::where('event_version_id', $this->days[0]['version']->getId())->first()->redeemed_at);
    }

    private function cancelBooking(int|string $bookingId)
    {
        return $this->graphQL('
            mutation cancel($id: ID!) {
                cancelTimeSlotBooking(id: $id) { id }
            }
        ', ['id' => (string) $bookingId], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }

    private function verifyInviteCode(string $code, ?string $productId = null)
    {
        return $this->graphQL('
            query verify($product_id: ID!, $code: String!) {
                verifyInviteCode(product_id: $product_id, code: $code) {
                    valid
                    time_slot { id available_slots }
                }
            }
        ', [
            'product_id' => $productId ?? (string) $this->gatedProduct->getId(),
            'code' => $code,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }

    private function flushAttempts(): void
    {
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('invite-code:ip:127.0.0.1');
    }
}
