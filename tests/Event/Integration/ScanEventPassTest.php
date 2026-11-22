<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassScan;
use Kanvas\Event\Passes\Actions\FindPassByCodeAction;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Passes\Services\PassScanDecisionGate;
use Kanvas\Event\Reports\Repositories\TimeSlotOccupancyRepository;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\Event\Concerns\BuildsInviteOnlyParking;
use Tests\Event\Support\PassScanDecisionTestActivity;
use Tests\Event\Support\PassScannedRecorderTestActivity;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncRules;

class ScanEventPassTest extends TestCase
{
    use BuildsInviteOnlyParking;
    use BuildsSyncRules;
    use DatabaseTransactions;
    use InventoryCases;

    protected $apps;
    protected $user;
    protected $company;
    protected $region;
    protected array $days;

    protected function connectionsToTransact(): array
    {
        return [null, 'event', 'commerce', 'workflow'];
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

    public function testTwoUsePassAdmitsTwiceAndRejectsTheThirdScan(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        $this->makeMultiUse($booking, 2);

        $first = $this->scan($code, $this->insideTheWindow(0));
        $second = $this->scan($code, $this->insideTheWindow(0)->addMinutes(30));
        $third = $this->scan($code, $this->insideTheWindow(0)->addMinutes(60));

        $this->assertSame(PassScanResultEnum::ADMITTED, $first->result);
        $this->assertSame(PassScanResultEnum::ADMITTED, $second->result);
        $this->assertSame(PassScanResultEnum::ALREADY_ADMITTED, $third->result);
        $scans = ParticipantPassScan::where('event_version_id', $booking->getId())->orderBy('id')->get();
        $this->assertSame([1, 2, null], $scans->map(fn (ParticipantPassScan $scan) => $scan->use_number)->all());
        $this->assertSame(1, $scans->whereNotNull('admitted_event_version_id')->count());
    }

    public function testUseCountNeverExceedsMaxUses(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        $this->makeMultiUse($booking, 2);

        foreach (range(1, 5) as $attempt) {
            $this->scan($code, $this->insideTheWindow(0)->addMinutes($attempt));
        }

        $passId = new FindPassByCodeAction($this->apps, $this->company, $code)->execute()->getId();
        $this->assertSame(2, (int) ParticipantPass::findOrFail($passId)->use_count);
        $this->assertSame(
            2,
            ParticipantPassScan::where('event_version_id', $booking->getId())->where('result', PassScanResultEnum::ADMITTED)->count()
        );
    }

    public function testTheExitUseIsAdmittedAfterTheWindowAndTheExpiry(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        $this->makeMultiUse($booking, 2);
        $this->scan($code, $this->insideTheWindow(0));

        $exit = $this->scan($code, $this->days[0]['slot']->end_at->copy()->addDays(5));

        $this->assertSame(PassScanResultEnum::ADMITTED, $exit->result);
    }

    public function testTheFirstUseOfAMultiUsePassStillNeedsTheWindow(): void
    {
        $booking = $this->bookDay(0);
        $this->makeMultiUse($booking, 2);

        $outcome = $this->scan($this->passCodeOf($booking), $this->days[0]['slot']->end_at->copy()->addHours(2));

        $this->assertSame(PassScanResultEnum::OUTSIDE_WINDOW, $outcome->result);
    }

    public function testTheExitUseIsRejectedWhenThePassIsRevokedOrTheBookingCancelled(): void
    {
        $revoked = $this->bookDay(0);
        $revokedCode = $this->passCodeOf($revoked);
        $this->makeMultiUse($revoked, 2);
        $this->scan($revokedCode, $this->insideTheWindow(0));
        ParticipantPass::where('event_version_id', $revoked->getId())->update(['revoked_at' => Carbon::now()]);

        $cancelled = $this->bookDay(1);
        $cancelledCode = $this->passCodeOf($cancelled);
        $this->makeMultiUse($cancelled, 2);
        $this->scan($cancelledCode, $this->insideTheWindow(1));
        new CancelEventAction($cancelled)->withoutNotifications()->disableWorkflow()->execute();

        $this->assertSame(PassScanResultEnum::NOT_ACTIVE, $this->scan($revokedCode, $this->insideTheWindow(0))->result);
        $this->assertSame(PassScanResultEnum::NOT_ACTIVE, $this->scan($cancelledCode, $this->insideTheWindow(1))->result);
    }

    public function testATwoUsePassCountsAsOneVisitInOccupancy(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        $this->makeMultiUse($booking, 2);
        $this->scan($code, $this->insideTheWindow(0));
        $this->scan($code, $this->insideTheWindow(0)->addHour());

        $occupancy = TimeSlotOccupancyRepository::occupancy(
            $this->apps,
            $this->company,
            $this->days[0]['slot']->start_at->copy()->startOfDay(),
            $this->days[0]['slot']->start_at->copy()->endOfDay()
        );

        $this->assertSame(1, $occupancy['admitted']);
    }

    public function testAPassRevokedBetweenTheReadAndTheLockIsNotActiveAndCountsAsAFailure(): void
    {
        $booking = $this->bookDay(0);
        $code = $this->passCodeOf($booking);
        $armed = true;
        ParticipantPass::retrieved(function (ParticipantPass $pass) use (&$armed) {
            if ($armed && DB::connection('event')->transactionLevel() > 1) {
                $pass->revoked_at = Carbon::now();
            }
        });

        $outcome = $this->scan($code, $this->insideTheWindow(0));
        $armed = false;

        $this->assertSame(PassScanResultEnum::NOT_ACTIVE, $outcome->result);
        $this->assertSame(1, RateLimiter::attempts('event-pass-scan:user:' . $this->user->getId()));
        $this->assertFalse(ParticipantPassScan::where('admitted_event_version_id', $booking->getId())->exists());
        $this->assertSame(0, (int) ParticipantPass::where('event_version_id', $booking->getId())->max('use_count'));
    }

    public function testAMultiUsePassWhoseSiblingWasAdmittedIsAlreadyAdmittedAndKeepsItsUseCount(): void
    {
        $booking = $this->bookDay(0);
        $this->makeMultiUse($booking, 2);
        $passes = ParticipantPass::where('event_version_id', $booking->getId())->orderBy('id')->get();
        $codePass = $passes->first(fn (ParticipantPass $pass) => $pass->participant_id === null);
        $sibling = $passes->first(fn (ParticipantPass $pass) => $pass->participant_id !== null);
        $this->scan(decrypt($sibling->code), $this->insideTheWindow(0));
        $this->assertSame(1, (int) $sibling->fresh()->use_count);

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0)->addMinutes(5));

        $this->assertSame(PassScanResultEnum::ALREADY_ADMITTED, $outcome->result);
        $this->assertSame(0, (int) $codePass->fresh()->use_count);
    }

    public function testALaterUseIsAdmittedWhenTheOnlySyncRuleAllows(): void
    {
        $booking = $this->decidedBooking(decisionFromUse: 2);
        $this->decisionRule($booking, 'allow');
        $code = $this->passCodeOf($booking);
        $this->scan($code, $this->insideTheWindow(0));

        $outcome = $this->scan($code, $this->insideTheWindow(0)->addHour());

        $this->assertSame(PassScanResultEnum::ADMITTED, $outcome->result);
        $this->assertSame(2, (int) $this->codePassOf($booking)->use_count);
    }

    public function testAnAdmittedScanFiresPassScannedWithTheUseDetails(): void
    {
        $booking = $this->bookDay(0);
        $this->makeMultiUse($booking, 2);
        $this->passScannedRule();
        $code = $this->passCodeOf($booking);
        $firstScanAt = $this->insideTheWindow(0);

        $this->scan($code, $firstScanAt);
        $this->scan($code, $firstScanAt->copy()->addHours(2));

        $this->assertCount(2, PassScannedRecorderTestActivity::$calls);
        [$first, $second] = PassScannedRecorderTestActivity::$calls;
        $this->assertSame($booking->getId(), $first['entity_id']);
        $this->assertSame(1, $first['params']['use_number']);
        $this->assertSame(2, $first['params']['max_uses']);
        $this->assertSame($firstScanAt->toIso8601String(), $first['params']['scanned_at']);
        $this->assertSame($this->gatedProduct->getId(), $first['params']['products_id']);
        $this->assertSame($this->codePassOf($booking)->getId(), $first['params']['participant_passes_id']);
        $this->assertSame(2, $second['params']['use_number']);
        $this->assertSame($firstScanAt->copy()->addHours(2)->toIso8601String(), $second['params']['scanned_at']);
    }

    public function testRejectedScansDoNotFirePassScanned(): void
    {
        $booking = $this->bookDay(0);
        $this->passScannedRule();
        $code = $this->passCodeOf($booking);

        $this->scan('NOT-A-CODE', $this->insideTheWindow(0));
        $this->scan($code, $this->days[0]['slot']->start_at->copy()->subHour());
        $this->assertSame([], PassScannedRecorderTestActivity::$calls);

        $this->scan($code, $this->insideTheWindow(0));
        $outcome = $this->scan($code, $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::ALREADY_ADMITTED, $outcome->result);
        $this->assertCount(1, PassScannedRecorderTestActivity::$calls);
    }

    public function testADeniedLaterUseDoesNotFirePassScanned(): void
    {
        $booking = $this->decidedBooking(decisionFromUse: 2);
        $this->decisionRule($booking, 'deny');
        $this->passScannedRule();
        $code = $this->passCodeOf($booking);

        $this->scan($code, $this->insideTheWindow(0));
        $outcome = $this->scan($code, $this->insideTheWindow(0)->addHour());

        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $outcome->result);
        $this->assertCount(1, PassScannedRecorderTestActivity::$calls);
    }

    public function testAFailingPassScannedRuleNeverBreaksTheAdmit(): void
    {
        $booking = $this->bookDay(0);
        $this->createSyncRule(
            app: $this->apps,
            event: WorkflowEnum::PASS_SCANNED->value,
            modelClass: EventVersion::class,
            activityClass: PassScanDecisionTestActivity::class,
            company: $this->company,
            params: ['verdict' => 'boom'],
            actionName: 'Test Pass Scan Decision',
        );

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::ADMITTED, $outcome->result);
    }

    private function passScannedRule(): Rule
    {
        PassScannedRecorderTestActivity::$calls = [];

        return $this->createSyncRule(
            app: $this->apps,
            event: WorkflowEnum::PASS_SCANNED->value,
            modelClass: EventVersion::class,
            activityClass: PassScannedRecorderTestActivity::class,
            company: $this->company,
            actionName: 'Test Pass Scanned Recorder',
        );
    }

    public function testTheDecisionRuleIsNotAskedForTheFirstUse(): void
    {
        $booking = $this->decidedBooking(decisionFromUse: 2);
        $this->decisionRule($booking, 'boom');

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::ADMITTED, $outcome->result);
    }

    public function testAPassWithoutADecisionUseNeverFiresTheEvent(): void
    {
        $booking = $this->bookDay(0);
        $this->makeMultiUse($booking, 2);
        $this->decisionRule($booking, 'deny');
        $code = $this->passCodeOf($booking);
        $this->scan($code, $this->insideTheWindow(0));

        $outcome = $this->scan($code, $this->insideTheWindow(0)->addHour());

        $this->assertSame(PassScanResultEnum::ADMITTED, $outcome->result);
    }

    public function testALaterUseIsDeniedWhenTheRuleDenies(): void
    {
        $this->assertDeniedLaterUse(['deny']);
    }

    public function testALaterUseIsDeniedWhenNoRuleExists(): void
    {
        $this->assertDeniedLaterUse([]);
    }

    public function testALaterUseIsDeniedWhenTheOnlyRuleIsAsync(): void
    {
        $this->assertDeniedLaterUse(['allow'], async: true);
    }

    public function testALaterUseIsDeniedWhenOneOfTwoSyncRulesDenies(): void
    {
        $this->assertDeniedLaterUse(['allow', 'deny']);
    }

    public function testALaterUseIsDeniedWhenTheRuleThrows(): void
    {
        $this->assertDeniedLaterUse(['boom']);
    }

    public function testALaterUseIsDeniedWhenTheRuleAnswersInAnotherShape(): void
    {
        $this->assertDeniedLaterUse(['truthy']);
    }

    public function testALaterUseIsDeniedWhenTheRuleNeverAnswersTheDecision(): void
    {
        $this->assertDeniedLaterUse(['silent']);
    }

    public function testAnEntryScanThatRacedPastTheGateCannotBecomeTheExitUse(): void
    {
        $booking = $this->decidedBooking(decisionFromUse: 2);
        $this->decisionRule($booking, 'allow');
        $code = $this->passCodeOf($booking);
        $armed = true;
        ParticipantPass::retrieved(function (ParticipantPass $pass) use (&$armed) {
            if ($armed && DB::connection('event')->transactionLevel() > 1) {
                $pass->use_count = 1;
            }
        });

        $outcome = $this->scan($code, $this->insideTheWindow(0));
        $armed = false;

        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $outcome->result);
        $this->assertSame(0, (int) $this->codePassOf($booking)->use_count);
        $this->assertSame(0, RateLimiter::attempts('event-pass-scan:user:' . $this->user->getId()));
        $this->assertFalse(ParticipantPassScan::where('result', PassScanResultEnum::ADMITTED)->where('participant_passes_id', $this->codePassOf($booking)->getId())->exists());
    }

    public function testAMultiUsePassWithAnUnreadablePayloadIsDenied(): void
    {
        $booking = $this->bookDay(0);
        $this->makeMultiUse($booking, 2);
        ParticipantPass::where('event_version_id', $booking->getId())->update(['payload' => '{broken']);
        $this->decisionRule($booking, 'allow');

        $outcome = $this->scan($this->passCodeOf($booking), $this->insideTheWindow(0));

        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $outcome->result);
        $this->assertSame(0, (int) $this->codePassOf($booking)->use_count);
    }

    public function testTheDecisionUseIsReadFromAnArrayPayloadToo(): void
    {
        $pass = new ParticipantPass(['max_uses' => 2]);
        $pass->payload = ['decision_from_use' => 2];

        $this->assertSame(2, PassScanDecisionGate::decisionFromUse($pass));
    }

    private function assertDeniedLaterUse(array $verdicts, bool $async = false): void
    {
        $booking = $this->decidedBooking(decisionFromUse: 2);

        foreach ($verdicts as $verdict) {
            $this->decisionRule($booking, $verdict, $async);
        }

        $code = $this->passCodeOf($booking);
        $this->scan($code, $this->insideTheWindow(0));
        $attemptsBefore = RateLimiter::attempts('event-pass-scan:user:' . $this->user->getId());

        $outcome = $this->scan($code, $this->insideTheWindow(0)->addHour());

        $this->assertSame(PassScanResultEnum::NOT_AUTHORIZED, $outcome->result);
        $this->assertSame(1, (int) $this->codePassOf($booking)->use_count);
        $this->assertSame($attemptsBefore, RateLimiter::attempts('event-pass-scan:user:' . $this->user->getId()));
        $this->assertTrue(
            ParticipantPassScan::where('result', PassScanResultEnum::NOT_AUTHORIZED)
                ->where('participant_passes_id', $this->codePassOf($booking)->getId())
                ->exists()
        );
    }

    private function decidedBooking(int $decisionFromUse): EventVersion
    {
        $booking = $this->bookDay(0);
        $this->makeMultiUse($booking, 2);
        ParticipantPass::where('event_version_id', $booking->getId())
            ->update(['payload' => json_encode(['decision_from_use' => $decisionFromUse])]);

        return $booking;
    }

    private function decisionRule(EventVersion $booking, string $verdict, bool $async = false): Rule
    {
        $rule = $this->createSyncRule(
            app: $this->apps,
            event: WorkflowEnum::PASS_SCAN_REQUESTED->value,
            modelClass: EventVersion::class,
            activityClass: PassScanDecisionTestActivity::class,
            company: $this->company,
            params: ['verdict' => $verdict],
            actionName: 'Test Pass Scan Decision',
        );
        $rule->is_async = $async;
        $rule->saveQuietly();

        return $rule;
    }

    private function codePassOf(EventVersion $booking): ParticipantPass
    {
        return ParticipantPass::where('event_version_id', $booking->getId())
            ->whereNull('participant_id')
            ->firstOrFail();
    }

    private function makeMultiUse(EventVersion $booking, int $maxUses): void
    {
        ParticipantPass::where('event_version_id', $booking->getId())->update(['max_uses' => $maxUses]);
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
        Bouncer::refreshFor($this->user);
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
