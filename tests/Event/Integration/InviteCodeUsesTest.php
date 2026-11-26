<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\RedeemPassAction;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class InviteCodeUsesTest extends TestCase
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

        $this->days = $this->buildInviteOnlyParking(days: 2, capacity: 3);
        $this->flushAttempts();
    }

    protected function tearDown(): void
    {
        $this->flushAttempts();

        parent::tearDown();
    }

    public function testATwoUseCodeBooksBothDaysAndAThirdBookingIsRefused(): void
    {
        $code = $this->issueEventCode(uses: 2);

        $friday = $this->book(0, $code);
        $saturday = $this->book(1, $code);
        $third = $this->book(0, $code);

        $this->assertNull($friday->json('errors'), json_encode($friday->json('errors')));
        $this->assertNull($saturday->json('errors'), json_encode($saturday->json('errors')));
        $this->assertNotSame($friday->json('data.bookTimeSlot.pass_code'), $saturday->json('data.bookTimeSlot.pass_code'));
        $this->assertNotSame($friday->json('data.bookTimeSlot.reference'), $saturday->json('data.bookTimeSlot.reference'));
        $this->assertSame('Access code already used.', $third->json('errors.0.message'));
    }

    public function testTheSameDayTwiceIsRefusedWithoutSpendingTheSecondUse(): void
    {
        $code = $this->issueEventCode(uses: 2);
        $this->book(0, $code);

        $again = $this->book(0, $code);

        $this->assertSame('Access code already used for this day.', $again->json('errors.0.message'));
        $this->assertSame(1, $this->passOf($code)->remainingUses());
        $this->assertNull($this->book(1, $code)->json('errors'));
    }

    public function testAOneUseCodeCanPickEitherDay(): void
    {
        $saturdayCode = $this->issueEventCode(uses: 1);
        $fridayCode = $this->issueEventCode(uses: 1);

        $saturday = $this->book(1, $saturdayCode);
        $friday = $this->book(0, $fridayCode);

        $this->assertNull($saturday->json('errors'), json_encode($saturday->json('errors')));
        $this->assertNull($friday->json('errors'), json_encode($friday->json('errors')));
        $this->assertSame('Access code already used.', $this->book(0, $saturdayCode)->json('errors.0.message'));
    }

    public function testVerifyListsOnlyTheDaysNotBookedYetAndTheRemainingUses(): void
    {
        $code = $this->issueEventCode(uses: 2);

        $before = $this->verify($code);
        $this->book(0, $code);
        $after = $this->verify($code);

        $this->assertSame('VALID', $before->json('data.verifyInviteCode.reason'));
        $this->assertSame(2, $before->json('data.verifyInviteCode.remaining_uses'));
        $this->assertCount(2, $before->json('data.verifyInviteCode.time_slots'));
        $this->assertSame('VALID', $after->json('data.verifyInviteCode.reason'));
        $this->assertSame(1, $after->json('data.verifyInviteCode.remaining_uses'));
        $this->assertSame(
            [(string) $this->days[1]['slot']->id],
            array_column($after->json('data.verifyInviteCode.time_slots'), 'id')
        );
    }

    public function testVerifyAnswersUsedWhenNoUsesAreLeftAndFullWhenEveryDayLeftIsFull(): void
    {
        $spent = $this->issueEventCode(uses: 1);
        $this->book(0, $spent);
        $fullCode = $this->issueEventCode(uses: 2);
        $this->days[0]['slot']->update(['initial_capacity' => 0]);
        $this->days[1]['slot']->update(['initial_capacity' => 0]);

        $used = $this->verify($spent);
        $full = $this->verify($fullCode);

        $this->assertSame('USED', $used->json('data.verifyInviteCode.reason'));
        $this->assertSame(0, $used->json('data.verifyInviteCode.remaining_uses'));
        $this->assertSame('FULL', $full->json('data.verifyInviteCode.reason'));
        $this->assertSame(2, $full->json('data.verifyInviteCode.remaining_uses'));
        $this->assertCount(2, $full->json('data.verifyInviteCode.time_slots'));
    }

    public function testAFullDayDoesNotSpendAUse(): void
    {
        $code = $this->issueEventCode(uses: 1);
        $this->days[0]['slot']->update(['initial_capacity' => 0]);

        $response = $this->book(0, $code);

        $this->assertSame('No capacity left for this day.', $response->json('errors.0.message'));
        $this->assertSame(1, $this->passOf($code)->remainingUses());
    }

    public function testASecondBookingWithTheLastUseCannotRedeem(): void
    {
        $code = $this->issueEventCode(uses: 1);
        $first = $this->bookingWithoutSlot();
        $second = $this->bookingWithoutSlot();

        new RedeemPassAction(
            $this->apps,
            $this->company,
            $code,
            $this->gatedEvent->versions()->orderBy('start_at')->firstOrFail(),
            $first
        )->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        new RedeemPassAction(
            $this->apps,
            $this->company,
            $code,
            $this->gatedEvent->versions()->orderBy('start_at')->firstOrFail(),
            $second
        )->execute();
    }

    public function testCancellingABookingFreesItsUse(): void
    {
        $code = $this->issueEventCode(uses: 1);
        $booking = EventVersion::findOrFail($this->book(0, $code)->json('data.bookTimeSlot.id'));
        $this->assertSame(0, $this->passOf($code)->remainingUses());

        new CancelEventAction($booking)->withoutNotifications()->disableWorkflow()->execute();

        $this->assertSame(1, $this->passOf($code)->remainingUses());
        $this->assertSame('available', $this->passOf($code)->inviteStatus());
        $this->assertNull($this->book(1, $code)->json('errors'));
    }

    public function testPartialCancellationKeepsTheCodeRedeemed(): void
    {
        $code = $this->issueEventCode(uses: 2);
        $first = EventVersion::findOrFail($this->book(0, $code)->json('data.bookTimeSlot.id'));
        $this->book(1, $code);

        new CancelEventAction($first)->withoutNotifications()->disableWorkflow()->execute();

        $this->assertSame('redeemed', $this->passOf($code)->inviteStatus());
        $this->assertSame(1, $this->passOf($code)->remainingUses());
    }

    public function testListingShowsUsesRemainingUsesAndStatus(): void
    {
        $code = $this->issueEventCode(uses: 2);
        $this->issueEventCode(uses: 1);
        $this->book(0, $code);

        $response = $this->graphQL('
            query codes($event_id: ID!, $status: EventInviteCodeStatus) {
                eventInviteCodes(event_id: $event_id, status: $status, first: 50) {
                    data { code uses remaining_uses status }
                    paginatorInfo { total }
                }
            }
        ', ['event_id' => (string) $this->gatedEvent->getId(), 'status' => 'REDEEMED'], [], $this->headers());

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(1, $response->json('data.eventInviteCodes.paginatorInfo.total'));
        $this->assertSame(
            ['code' => $code, 'uses' => 2, 'remaining_uses' => 1, 'status' => 'REDEEMED'],
            $response->json('data.eventInviteCodes.data.0')
        );
    }

    private function passOf(string $code): ParticipantPass
    {
        return ParticipantPass::query()
            ->where('event_id', $this->gatedEvent->getId())
            ->get()
            ->first(fn (ParticipantPass $pass) => $pass->plainCode() === $code);
    }

    private function bookingWithoutSlot(): EventVersion
    {
        return EventVersion::create([
            'event_id' => $this->createEvent()->getId(),
            'users_id' => $this->user->getId(),
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->apps->getId(),
            'currency_id' => 1,
            'name' => 'Booking ' . uniqid(),
            'version_number' => 1,
            'version' => '1',
            'price_per_ticket' => 0,
            'total_attendees' => 0,
            'start_at' => now()->addDays(10),
            'end_at' => now()->addDays(10)->addHours(2),
        ]);
    }

    private function book(int $day, string $code): TestResponse
    {
        $this->flushAttempts();

        return $this->graphQL('
            mutation book($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id reference pass_code }
            }
        ', [
            'input' => ['time_slot_id' => (string) $this->days[$day]['slot']->id, 'invite_code' => $code],
        ], [], $this->headers());
    }

    private function verify(string $code): TestResponse
    {
        $this->flushAttempts();

        return $this->graphQL('
            query verify($product_id: ID!, $code: String!) {
                verifyInviteCode(product_id: $product_id, code: $code) {
                    valid
                    reason
                    remaining_uses
                    time_slots { id }
                }
            }
        ', ['product_id' => (string) $this->gatedProduct->getId(), 'code' => $code], [], $this->headers());
    }

    private function headers(): array
    {
        return [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ];
    }

    private function flushAttempts(): void
    {
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('invite-code:ip:127.0.0.1');
    }
}
