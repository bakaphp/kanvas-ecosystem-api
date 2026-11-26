<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\Actions\AssignBookingReferenceAction;
use Kanvas\Event\Passes\DataTransferObject\EventAccess;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class BookingReferenceTest extends TestCase
{
    use BuildsInviteOnlyParking;
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

        $this->days = $this->buildInviteOnlyParking(days: 1, capacity: 5);
        $this->flushAttempts();
    }

    protected function tearDown(): void
    {
        $this->flushAttempts();

        parent::tearDown();
    }

    public function testReferencesAreSequentialAcrossTheLotsOfTheSameEvent(): void
    {
        $first = $this->bookInviteOnDay($this->days[0]);
        $this->buildGatedProduct($this->gatedEvent);
        $secondLot = [
            'version' => $this->days[0]['version'],
            'slot' => $this->makeDaySlot($this->gatedVariant, $this->days[0]['start']->copy(), 5),
        ];
        $second = $this->bookInviteOnDay($secondLot);

        $this->assertMatchesRegularExpression('/^MP-\d{4,}$/', $first->reference);
        $this->assertSame($this->numberOf($first) + 1, $this->numberOf($second));
    }

    public function testTheNumberIsPaddedToFourDigits(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);

        $this->assertSame(sprintf('MP-%04d', $this->numberOf($booking)), $booking->reference);
    }

    public function testACustomPrefixStartsItsOwnSequence(): void
    {
        $this->buildGatedProduct($this->gatedEvent, [
            'event_id' => $this->gatedEvent->getId(),
            'reference_prefix' => 'fp',
        ]);
        $slot = $this->makeDaySlot($this->gatedVariant, $this->days[0]['start']->copy(), 5);

        $booking = $this->bookInviteOnDay(['version' => $this->days[0]['version'], 'slot' => $slot]);

        $this->assertSame('FP-0001', $booking->reference);
    }

    public function testAMalformedPrefixLocksTheProduct(): void
    {
        $this->buildGatedProduct($this->gatedEvent, [
            'event_id' => $this->gatedEvent->getId(),
            'reference_prefix' => 'TOOLONGPREFIX',
        ]);
        $slot = $this->makeDaySlot($this->gatedVariant, $this->days[0]['start']->copy(), 5);

        $response = $this->bookWithInvite($slot, $this->issueInvite($this->days[0]['version']));

        $this->assertStringContainsString('Invalid access code', (string) $response->json('errors.0.message'));
    }

    public function testBackToBackBookingsNeverShareAReference(): void
    {
        $first = $this->bookInviteOnDay($this->days[0]);
        $second = $this->bookInviteOnDay($this->days[0]);
        $third = $this->bookInviteOnDay($this->days[0]);

        $this->assertCount(3, array_unique([$first->reference, $second->reference, $third->reference]));
        $this->assertSame($this->numberOf($first) + 2, $this->numberOf($third));
    }

    public function testAssigningAgainTakesTheNextFreeNumber(): void
    {
        $first = $this->bookInviteOnDay($this->days[0]);
        $access = new EventAccess(eventId: $this->gatedEvent->getId());

        $expected = $this->numberOf($first) + 1;

        $reference = new AssignBookingReferenceAction($access, $first)->execute();

        $this->assertSame($expected, (int) substr($reference, 3));
    }

    public function testTheOwnerSeesTheReferenceInTheBookingResponse(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);
        $response = $this->graphQL('
            mutation book($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id reference pass_code }
            }
        ', [
            'input' => ['time_slot_id' => (string) $this->days[0]['slot']->id, 'invite_code' => $code],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertMatchesRegularExpression('/^MP-\d{4,}$/', $response->json('data.bookTimeSlot.reference'));
        $this->assertNotSame($response->json('data.bookTimeSlot.reference'), $response->json('data.bookTimeSlot.pass_code'));
    }

    private function numberOf(EventVersion $booking): int
    {
        return (int) substr((string) $booking->reference, strpos((string) $booking->reference, '-') + 1);
    }

    private function flushAttempts(): void
    {
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('invite-code:ip:127.0.0.1');
    }
}
