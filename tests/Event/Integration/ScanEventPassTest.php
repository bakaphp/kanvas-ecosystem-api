<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassScan;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ScanEventPassTest extends TestCase
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
        $this->clearLimiters();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->clearLimiters();

        parent::tearDown();
    }

    public function testValidBookingPassIsAdmittedAndSummarized(): void
    {
        $booking = $this->bookDay(0, ['arrival_hour' => '18:00', 'vehiclePlate' => 'A123456']);

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::ADMITTED, $outcome->result);
        $this->assertTrue($outcome->admitted());
        $this->assertSame('A123456', $outcome->booking['plate']);
        $this->assertSame('18:00', $outcome->booking['arrival_hour']);
        $this->assertSame($this->days[0]['slot']->start_at->toDateString(), $outcome->booking['date']);
        $this->assertNotNull($outcome->booking['guest']);
        $this->assertSame(0, ParticipantPass::where('event_version_id', $booking->getId())->whereNull('used_date')->count());
        $this->assertTrue(ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->exists());
    }

    public function testTheBookingExposesAnEightCharacterPassCodeToItsOwner(): void
    {
        $code = $this->issueInvite($this->days[0]['version']);

        $response = $this->graphQL('
            mutation bookTimeSlot($input: TimeSlotBookingInput!) {
                bookTimeSlot(input: $input) { id pass_code }
            }
        ', [
            'input' => ['time_slot_id' => (string) $this->days[0]['slot']->id, 'invite_code' => $code],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $passCode = $response->json('data.bookTimeSlot.pass_code');
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/', $passCode);
        $this->assertNotSame($code, $passCode);
        $this->assertSame(
            2,
            ParticipantPass::where('event_version_id', $response->json('data.bookTimeSlot.id'))->count()
        );
    }

    public function testSecondScanIsAlreadyAdmittedAndOnlyOneAdmissionExists(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);

        $first = $this->scan($code, $this->insideTheWindow(0));
        $second = $this->scan($code, $this->insideTheWindow(0)->addMinutes(5));

        $this->assertSame(PassScanResultEnum::ADMITTED, $first->result);
        $this->assertSame(PassScanResultEnum::ALREADY_ADMITTED, $second->result);
        $this->assertFalse($second->admitted());
        $this->assertSame(1, ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->count());
        $this->assertSame(2, ParticipantPassScan::where('event_version_id', $booking->getId())->count());
    }

    public function testTheParticipantCodeOfAnAdmittedBookingIsNotAdmittedAgain(): void
    {
        $booking = $this->bookDay(0);
        $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0));
        $participantPass = ParticipantPass::where('event_version_id', $booking->getId())->whereNotNull('participant_id')->firstOrFail();

        $outcome = $this->scan(decrypt($participantPass->code), $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::ALREADY_ADMITTED, $outcome->result);
    }

    public function testDatabaseRefusesASecondAdmissionForTheSameBooking(): void
    {
        $booking = $this->bookDay(0);
        $row = [
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'event_version_id' => $booking->getId(),
            'products_id' => $this->gatedProduct->getId(),
            'users_id' => $this->user->getId(),
            'result' => PassScanResultEnum::ADMITTED,
            'admitted_event_version_id' => $booking->getId(),
            'scanned_at' => Carbon::now(),
        ];
        ParticipantPassScan::create($row);

        $this->expectException(UniqueConstraintViolationException::class);

        ParticipantPassScan::create($row);
    }

    public function testUnknownCodeIsInvalid(): void
    {
        $outcome = $this->scan('NOSUCHCODE', $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::INVALID_CODE, $outcome->result);
        $this->assertNull($outcome->booking);
    }

    public function testTheGuestListInviteCodeDoesNotOpenTheGate(): void
    {
        $inviteCode = $this->issueInvite($this->days[0]['version']);

        $outcome = $this->scan($inviteCode, $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::INVALID_CODE, $outcome->result);
    }

    public function testCancelledBookingIsNotActive(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        new CancelEventAction($booking)->withoutNotifications()->disableWorkflow()->execute();

        $outcome = $this->scan($code, $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::NOT_ACTIVE, $outcome->result);
        $this->assertFalse(ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->exists());
    }

    public function testRevokedPassIsNotActive(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        ParticipantPass::where('event_version_id', $booking->getId())->update(['revoked_at' => Carbon::now()]);

        $this->assertSame(PassScanResultEnum::NOT_ACTIVE, $this->scan($code, $this->insideTheWindow(0))->result);
    }

    public function testPassOfAnotherProductIsWrongResource(): void
    {
        $booking = $this->bookDay(0);
        $otherProduct = Products::find($this->graphQLData($this->createProduct(), 'createProduct')['id']);

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0), $otherProduct);

        $this->assertSame(PassScanResultEnum::WRONG_RESOURCE, $outcome->result);
    }

    public function testPassOfAnotherDayIsOutsideTheWindow(): void
    {
        $booking = $this->bookDay(0);

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(1));

        $this->assertSame(PassScanResultEnum::OUTSIDE_WINDOW, $outcome->result);
    }

    public function testWindowEdgesAreInclusiveAndTheEndOfTheSlotClosesTheGate(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        $slot = $this->days[0]['slot'];

        $this->assertSame(PassScanResultEnum::OUTSIDE_WINDOW, $this->scan($code, $slot->start_at->copy()->subSecond())->result);
        $this->assertSame(PassScanResultEnum::OUTSIDE_WINDOW, $this->scan($code, $slot->end_at->copy()->addSecond())->result);
        $this->assertSame(PassScanResultEnum::ADMITTED, $this->scan($code, $slot->end_at->copy())->result);
    }

    public function testScannerOfAnotherCompanyIsForbidden(): void
    {
        $booking = $this->bookDay(0);
        $otherCompany = Companies::where('id', '!=', $this->company->getId())->firstOrFail();

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0), scannerCompany: $otherCompany);

        $this->assertSame(PassScanResultEnum::FORBIDDEN, $outcome->result);
        $this->assertFalse(ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->exists());
    }

    public function testEveryScanIsLoggedWhetherItAdmitsOrNot(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);

        $this->scan('NOSUCHCODE', $this->insideTheWindow(0));
        $this->scan($code, $this->insideTheWindow(1));
        $this->scan($code, $this->insideTheWindow(0));
        $this->scan($code, $this->insideTheWindow(0));

        $scans = ParticipantPassScan::where('products_id', $this->gatedProduct->getId())->orderBy('id')->get();
        $results = $scans->map(fn (ParticipantPassScan $scan) => $scan->result->value)->all();
        $this->assertSame(['invalid_code', 'outside_window', 'admitted', 'already_admitted'], $results);
        $this->assertSame($this->user->getId(), (int) $scans->first()->users_id);
    }

    public function testRepeatedFailedScansAreRateLimited(): void
    {
        foreach (range(1, ScanEventPassAction::MAX_FAILURES_PER_MINUTE) as $attempt) {
            $this->scan('WRONG' . $attempt, $this->insideTheWindow(0));
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Too many invalid attempts');

        $this->scan('ANYTHING', $this->insideTheWindow(0));
    }

    public function testAnAdmissionDoesNotCountAsAFailure(): void
    {
        $booking = $this->bookDay(0);

        $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0));

        $this->assertSame(0, RateLimiter::attempts('event-pass-scan:user:' . $this->user->getId()));
    }

    public function testScanMutationAdmitsForAUserWithTheAbility(): void
    {
        $this->grantValidatePass();
        $booking = $this->bookDay(0);
        Carbon::setTestNow($this->insideTheWindow(0));

        $response = $this->scanMutation($this->passCodeOf($booking), (string) $this->gatedProduct->getId());

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame('ADMITTED', $response->json('data.scanEventPass.result'));
        $this->assertTrue($response->json('data.scanEventPass.admitted'));
        $this->assertSame($this->days[0]['slot']->start_at->toDateString(), $response->json('data.scanEventPass.booking.date'));
    }

    public function testScanMutationReportsAlreadyAdmittedWithoutAdmitting(): void
    {
        $this->grantValidatePass();
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        Carbon::setTestNow($this->insideTheWindow(0));
        $this->scanMutation($code, (string) $this->gatedProduct->getId());

        $second = $this->scanMutation($code, (string) $this->gatedProduct->getId());

        $this->assertSame('ALREADY_ADMITTED', $second->json('data.scanEventPass.result'));
        $this->assertFalse($second->json('data.scanEventPass.admitted'));
    }

    private function bookDay(int $day, array $metadata = []): EventVersion
    {
        $code = $this->issueInvite($this->days[$day]['version']);
        $response = $this->bookWithInvite($this->days[$day]['slot'], $code, $metadata);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        RateLimiter::clear('invite-code:user:' . $this->user->getId());

        return EventVersion::findOrFail($response->json('data.bookTimeSlot.id'));
    }

    private function passCodeOf(EventVersion $booking): string
    {
        return (string) $booking->getPassCode();
    }

    private function insideTheWindow(int $day): Carbon
    {
        return $this->days[$day]['slot']->start_at->copy()->addHours(3);
    }

    private function scan(
        string $code,
        Carbon $at,
        ?Products $product = null,
        ?Companies $scannerCompany = null
    ): PassScanOutcome {
        Carbon::setTestNow($at);

        return new ScanEventPassAction(
            $this->apps,
            $this->user,
            $scannerCompany ?? $this->company,
            $product ?? $this->gatedProduct
        )->execute($code);
    }

    private function grantValidatePass(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->apps, global: true));
        Bouncer::assign('Admins')->to($this->user);
        Bouncer::allow('Admins')->to('validate-pass');
    }

    private function scanMutation(string $code, string $productId)
    {
        return $this->graphQL('
            mutation scan($code: String!, $product_id: ID!) {
                scanEventPass(code: $code, product_id: $product_id) {
                    result
                    admitted
                    booking { plate arrival_hour date guest }
                }
            }
        ', ['code' => $code, 'product_id' => $productId], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }

    private function clearLimiters(): void
    {
        RateLimiter::clear('invite-code:user:' . $this->user->getId());
        RateLimiter::clear('event-pass-scan:user:' . $this->user->getId());
    }
}
