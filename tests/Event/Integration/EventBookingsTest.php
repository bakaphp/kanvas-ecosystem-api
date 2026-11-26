<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Participants\Models\ParticipantPassScan;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class EventBookingsTest extends TestCase
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
        $this->configureBookingOrderType();
        $this->grantValidatePass();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('invite-code:ip:127.0.0.1');
        RateLimiter::clear('event-pass-scan:user:' . $this->user->getId());

        parent::tearDown();
    }

    public function testTheListShowsTheBookingTheWayTheGateNeedsIt(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0], ['arrival_hour' => '18:00', 'vehiclePlate' => 'A123456']);

        $row = $this->bookings()->json('data.eventBookings.data.0');

        $this->assertSame($booking->reference, $row['reference']);
        $this->assertMatchesRegularExpression('/^MP-\d{4,}$/', $row['reference']);
        $this->assertSame($booking->getPassCode(), $row['pass_code']);
        $this->assertNotNull($row['guest']);
        $this->assertSame('18:00', $row['arrival_hour']);
        $this->assertSame('A123456', $row['plate']);
        $this->assertSame((string) $this->gatedProduct->name, $row['lot']);
        $this->assertSame($this->days[0]['slot']->start_at->toDateString(), $row['date']);
        $this->assertSame('BOOKED', $row['status']);
        $this->assertNull($row['admitted_at']);
        $this->assertNull($row['admitted_via']);
    }

    public function testCancelledBookingsAreLeftOut(): void
    {
        $this->bookInviteOnDay($this->days[0]);
        $cancelled = $this->bookInviteOnDay($this->days[0]);
        new CancelEventAction($cancelled)->withoutNotifications()->disableWorkflow()->execute();

        $this->assertCount(1, $this->bookings()->json('data.eventBookings.data'));
    }

    public function testItFiltersByDayProductAndReference(): void
    {
        $first = $this->bookInviteOnDay($this->days[0]);
        $this->bookInviteOnDay($this->days[1]);
        $otherProduct = $this->graphQLData($this->createProduct(), 'createProduct');

        $byDay = $this->bookings(['date' => $this->days[1]['slot']->start_at->toDateString()]);
        $byProduct = $this->bookings(['product_id' => (string) $this->gatedProduct->getId()]);
        $byOtherProduct = $this->bookings(['product_id' => (string) $otherProduct['id']]);
        $byReference = $this->bookings(['reference' => strtolower((string) $first->reference)]);
        $byUnknown = $this->bookings(['reference' => 'NOSUCHCOD']);

        $this->assertNull($byDay->json('errors'), json_encode($byDay->json('errors')));
        $this->assertNull($byProduct->json('errors'), json_encode($byProduct->json('errors')));
        $this->assertNull($byReference->json('errors'), json_encode($byReference->json('errors')));
        $this->assertCount(1, $byDay->json('data.eventBookings.data'));
        $this->assertCount(2, $byProduct->json('data.eventBookings.data'));
        $this->assertCount(0, $byOtherProduct->json('data.eventBookings.data'));
        $this->assertSame([$first->reference], array_column($byReference->json('data.eventBookings.data'), 'reference'));
        $this->assertCount(0, $byUnknown->json('data.eventBookings.data'));
    }

    public function testAQrAdmissionShowsAsAdmittedViaQr(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        $this->scanByQr($booking, $this->insideTheWindow(0));

        $row = $this->bookings()->json('data.eventBookings.data.0');

        $this->assertSame('ADMITTED', $row['status']);
        $this->assertSame('QR', $row['admitted_via']);
        $this->assertSame($this->localTime($this->insideTheWindow(0)), $row['admitted_at']);
    }

    public function testManualAdmissionKeepsTheTimeOfALateUpload(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        Carbon::setTestNow($this->insideTheWindow(0)->addHours(2));
        $admittedAt = $this->insideTheWindow(0);

        $response = $this->admit((string) $booking->reference, $this->localTime($admittedAt));
        $row = $this->bookings()->json('data.eventBookings.data.0');

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame('ADMITTED', $response->json('data.admitEventBooking.result'));
        $this->assertTrue($response->json('data.admitEventBooking.admitted'));
        $this->assertSame('MANUAL', $row['admitted_via']);
        $this->assertSame($this->localTime($admittedAt), $row['admitted_at']);
        $this->assertSame('manual', ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->first()->source->value);
    }

    public function testManualAdmissionTimeIsReadInTheLotTimezone(): void
    {
        $this->company->timezone = 'America/Santo_Domingo';
        $this->company->saveOrFail();
        $this->gatedProduct->setRelation('company', $this->company);
        $booking = $this->bookInviteOnDay($this->days[0]);
        $admittedAt = $this->insideTheWindow(0);
        Carbon::setTestNow($admittedAt->copy()->addHour());

        $response = $this->admit((string) $booking->reference, $this->localTime($admittedAt));
        $scan = ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->firstOrFail();

        $this->assertSame('ADMITTED', $response->json('data.admitEventBooking.result'), (string) $response->getContent());
        $this->assertSame($admittedAt->toDateTimeString(), Carbon::parse($scan->scanned_at)->utc()->toDateTimeString());
        $this->assertSame($this->localTime($admittedAt), $this->bookings()->json('data.eventBookings.data.0.admitted_at'));
    }

    public function testManualAdmissionAcceptsTheReferenceInAnyCaseAndEchoesIt(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        Carbon::setTestNow($this->insideTheWindow(0));

        $response = $this->admit(strtolower((string) $booking->reference));

        $this->assertSame('ADMITTED', $response->json('data.admitEventBooking.result'));
        $this->assertSame($booking->reference, $response->json('data.admitEventBooking.booking.reference'));
    }

    public function testManualAdmissionDefaultsToNow(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        Carbon::setTestNow($this->insideTheWindow(0));

        $this->admit((string) $booking->reference);

        $this->assertSame(
            $this->localTime($this->insideTheWindow(0)),
            $this->bookings()->json('data.eventBookings.data.0.admitted_at')
        );
    }

    public function testManualAfterQrIsAlreadyAdmitted(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        $this->scanByQr($booking, $this->insideTheWindow(0));
        Carbon::setTestNow($this->insideTheWindow(0));

        $response = $this->admit((string) $booking->reference);

        $this->assertSame('ALREADY_ADMITTED', $response->json('data.admitEventBooking.result'));
        $this->assertSame(1, ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->count());
    }

    public function testQrAfterManualIsAlreadyAdmitted(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        Carbon::setTestNow($this->insideTheWindow(0));
        $this->admit((string) $booking->reference);

        $outcome = $this->scanByQr($booking, $this->insideTheWindow(0));

        $this->assertSame('already_admitted', $outcome->result->value);
        $this->assertSame('MANUAL', $this->bookings()->json('data.eventBookings.data.0.admitted_via'));
    }

    public function testManualAdmissionOutsideTheWindowIsRejected(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        Carbon::setTestNow($this->insideTheWindow(1));

        $response = $this->admit((string) $booking->reference);

        $this->assertSame('OUTSIDE_WINDOW', $response->json('data.admitEventBooking.result'));
        $this->assertFalse(ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->exists());
    }

    public function testManualAdmissionOfAnUnknownReferenceIsInvalid(): void
    {
        Carbon::setTestNow($this->insideTheWindow(0));

        $response = $this->admit('NOSUCHCOD');

        $this->assertSame('INVALID_CODE', $response->json('data.admitEventBooking.result'));
    }

    public function testTheStatsSplitAdmissionsByHowTheyHappened(): void
    {
        $byQr = $this->bookInviteOnDay($this->days[0]);
        $byHand = $this->bookInviteOnDay($this->days[0]);
        $this->bookInviteOnDay($this->days[0]);
        $this->scanByQr($byQr, $this->insideTheWindow(0));
        Carbon::setTestNow($this->insideTheWindow(0));
        $this->admit((string) $byHand->getPassCode());
        Carbon::setTestNow();

        $stats = $this->graphQL('
            query stats($input: TimeSlotStatsInput!) {
                timeSlotStats(input: $input) { booked admitted admitted_qr admitted_manual }
            }
        ', [
            'input' => [
                'startDate' => Carbon::now()->format('Y-m-d'),
                'endDate' => Carbon::now()->addDays(20)->format('Y-m-d'),
                'timezone' => 'UTC',
                'resources_id' => (string) $this->gatedVariant->getId(),
                'resources_type' => $this->gatedVariant->getMorphClass(),
            ],
        ], [], $this->headers())->json('data.timeSlotStats');

        $this->assertSame(3, $stats['booked']);
        $this->assertSame(2, $stats['admitted']);
        $this->assertSame(1, $stats['admitted_qr']);
        $this->assertSame(1, $stats['admitted_manual']);
    }

    public function testAnotherCompanysBookingsAreNeverListed(): void
    {
        $booking = $this->bookInviteOnDay($this->days[0]);
        $otherCompany = Companies::factory()->create();
        EventVersion::whereKey($booking->getId())->update(['companies_id' => $otherCompany->getId()]);

        $response = $this->bookings();

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertCount(0, $response->json('data.eventBookings.data'));
    }

    public function testAnEventOfAnotherCompanyIsNotFound(): void
    {
        $otherCompany = Companies::factory()->create();
        $this->gatedEvent->update(['companies_id' => $otherCompany->getId()]);

        $response = $this->bookings();

        $this->assertNotNull($response->json('errors'));
    }

    private function bookings(array $filters = []): TestResponse
    {
        $arguments = ['event_id' => (string) $this->gatedEvent->getId(), ...$filters];
        $declared = [
            'event_id' => 'ID!',
            'product_id' => 'ID',
            'date' => 'Date',
            'reference' => 'String',
        ];
        $variables = implode(', ', array_map(
            fn (string $name) => '$' . $name . ': ' . $declared[$name],
            array_keys($arguments)
        ));
        $passed = implode(', ', array_map(fn (string $name) => $name . ': $' . $name, array_keys($arguments)));

        return $this->graphQL('
            query bookings(' . $variables . ') {
                eventBookings(' . $passed . ', first: 50) {
                    data {
                        reference pass_code guest arrival_hour plate lot date status admitted_at admitted_via
                    }
                }
            }
        ', $arguments, [], $this->headers());
    }

    private function admit(string $reference, ?string $admittedAt = null): TestResponse
    {
        return $this->graphQL('
            mutation admit($product_id: ID!, $reference: String!, $admitted_at: DateTime) {
                admitEventBooking(product_id: $product_id, reference: $reference, admitted_at: $admitted_at) {
                    result
                    admitted
                    booking { reference }
                }
            }
        ', [
            'product_id' => (string) $this->gatedProduct->getId(),
            'reference' => $reference,
            'admitted_at' => $admittedAt,
        ], [], $this->headers());
    }

    private function scanByQr(EventVersion $booking, Carbon $at)
    {
        Carbon::setTestNow($at);

        return new ScanEventPassAction(
            $this->apps,
            $this->user,
            $this->company,
            $this->gatedProduct
        )->execute((string) $booking->getPassCode());
    }

    private function insideTheWindow(int $day): Carbon
    {
        return $this->days[$day]['slot']->start_at->copy()->addHours(3);
    }

    private function localTime(Carbon $moment): string
    {
        return $moment->copy()
            ->setTimezone(ResourceTimezoneService::resolve($this->gatedProduct))
            ->format('Y-m-d H:i:s');
    }

    private function headers(): array
    {
        return [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ];
    }

    private function grantValidatePass(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->apps, global: true));
        Bouncer::assign('Admins')->to($this->user);
        Bouncer::allow('Admins')->to('validate-pass');
        Bouncer::refreshFor($this->user);
    }
}
