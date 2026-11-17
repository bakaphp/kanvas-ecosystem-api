<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Notifications\EventParticipantNotification;
use Kanvas\Event\Participants\Jobs\ImportEventParticipantsJob;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\RedeemPassAction;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Tests\Event\Concerns\CreatesEventVersions;
use Tests\TestCase;

class ImportEventParticipantsTest extends TestCase
{
    use CreatesEventVersions;
    use DatabaseTransactions;

    protected $apps;
    protected $user;
    protected $company;
    protected EventVersion $eventVersion;

    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();

        new Setup($this->apps, $this->user, $this->company)->run();

        $this->eventVersion = $this->createEventVersion($this->createEvent());
    }

    public function testImportCreatesParticipantsPassesAndSendsCodes(): void
    {
        Notification::fake();
        $emails = [$this->uniqueEmail(), $this->uniqueEmail(), $this->uniqueEmail()];

        $this->runJob(array_map(fn (string $email) => ['email' => $email, 'firstname' => 'Guest'], $emails));

        $this->assertSame(3, $this->eventVersion->participants()->count());
        $this->assertSame(3, $this->activePasses()->count());
        Notification::assertSentOnDemandTimes(EventParticipantNotification::class, 3);
    }

    public function testReimportDoesNotIssueSecondPassOrResend(): void
    {
        Notification::fake();
        $rows = [['email' => $this->uniqueEmail()]];

        $this->runJob($rows);
        $this->runJob($rows);

        $this->assertSame(1, $this->eventVersion->participants()->count());
        $this->assertSame(1, $this->activePasses()->count());
        Notification::assertSentOnDemandTimes(EventParticipantNotification::class, 1);
    }

    public function testRevokedPassIsReplacedOnReimport(): void
    {
        Notification::fake();
        $rows = [['email' => $this->uniqueEmail()]];

        $this->runJob($rows);
        $this->activePasses()->update(['revoked_at' => Carbon::now()]);
        $this->runJob($rows);

        $this->assertSame(1, $this->activePasses()->count());
        $this->assertSame(2, $this->allPasses()->count());
    }

    public function testBadRowDoesNotAbortTheBatch(): void
    {
        Notification::fake();

        $this->runJob([
            ['firstname' => 'No email'],
            ['email' => $this->uniqueEmail(), 'firstname' => 'Valid'],
        ]);

        $this->assertSame(1, $this->activePasses()->count());
        $this->assertSame(1, $this->eventVersion->participants()->count());
    }

    public function testImportIssuesAlphanumericCodeOfRequestedLength(): void
    {
        Notification::fake();

        $this->runJob([['email' => $this->uniqueEmail()]], codeLength: 10);

        $pass = $this->activePasses()->firstOrFail();
        $this->assertSame(10, strlen(decrypt($pass->code)));
        $this->assertSame('qr', $pass->format);
    }

    public function testNotificationCarriesTheCodeThatRedeems(): void
    {
        Notification::fake();

        $this->runJob([['email' => $this->uniqueEmail()]]);

        $sentCode = null;
        Notification::assertSentOnDemand(
            EventParticipantNotification::class,
            function (EventParticipantNotification $notification) use (&$sentCode) {
                $sentCode = $notification->getData()['code'] ?? null;

                return $sentCode !== null;
            }
        );

        $pass = new RedeemPassAction(
            $this->apps,
            $this->company,
            $sentCode,
            $this->eventVersion,
            $this->createEventVersion($this->createEvent())
        )->execute();

        $this->assertNotNull($pass->redeemed_at);
    }

    public function testReissueRevokesUnredeemedPassAndOldCodeStopsWorking(): void
    {
        Notification::fake();
        $rows = [['email' => $this->uniqueEmail()]];

        $this->runJob($rows);
        $oldPass = $this->activePasses()->firstOrFail();
        $oldCode = decrypt($oldPass->code);

        $this->runJob($rows, reissue: true);

        $this->assertNotNull($oldPass->refresh()->revoked_at);
        $this->assertSame(1, $this->activePasses()->count());
        Notification::assertSentOnDemandTimes(EventParticipantNotification::class, 2);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');
        new RedeemPassAction(
            $this->apps,
            $this->company,
            $oldCode,
            $this->eventVersion,
            $this->createEventVersion($this->createEvent())
        )->execute();
    }

    public function testRedeemedPassIsNeverReissued(): void
    {
        Notification::fake();
        $rows = [['email' => $this->uniqueEmail()]];

        $this->runJob($rows);
        $pass = $this->activePasses()->firstOrFail();
        $pass->update(['redeemed_at' => Carbon::now(), 'redeemed_event_version_id' => $this->eventVersion->getId()]);

        $this->runJob($rows, reissue: true);

        $this->assertNull($pass->refresh()->revoked_at);
        $this->assertSame(1, $this->allPasses()->count());
        Notification::assertSentOnDemandTimes(EventParticipantNotification::class, 1);
    }

    public function testRowWhoseLockIsHeldIsSkipped(): void
    {
        Notification::fake();
        $email = $this->uniqueEmail();
        $lock = Cache::lock('event-import:' . $this->eventVersion->getId() . ':' . $email, 60);
        $this->assertTrue($lock->get());

        try {
            $this->runJob([['email' => $email]]);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $this->allPasses()->count());
        Notification::assertNothingSent();
    }

    public function testGraphQLQueuesChunkedJobs(): void
    {
        Queue::fake();
        $rows = array_map(fn () => ['email' => $this->uniqueEmail()], range(1, 150));

        $response = $this->importMutation($this->eventVersion->getId(), $rows);

        $this->assertNull($response->json('errors'));
        $this->assertSame(150, $response->json('data.importEventParticipants.queued'));
        Queue::assertPushed(ImportEventParticipantsJob::class, 2);
    }

    public function testGraphQLRejectsInvalidEmailsAndDedupesRows(): void
    {
        Queue::fake();

        $invalid = $this->importMutation($this->eventVersion->getId(), [['email' => 'not-an-email']]);
        $this->assertNotNull($invalid->json('errors'));
        Queue::assertNotPushed(ImportEventParticipantsJob::class);

        $duplicate = $this->uniqueEmail();
        $deduped = $this->importMutation(
            $this->eventVersion->getId(),
            [['email' => $duplicate], ['email' => strtoupper($duplicate)]]
        );
        $this->assertSame(1, $deduped->json('data.importEventParticipants.queued'));
    }

    public function testGraphQLRejectsNonAdmin(): void
    {
        Queue::fake();
        $this->actingAs($this->createUser(), 'api');

        $response = $this->importMutation($this->eventVersion->getId(), [['email' => $this->uniqueEmail()]]);

        $this->assertNotNull($response->json('errors'));
        Queue::assertNotPushed(ImportEventParticipantsJob::class);
    }

    public function testGraphQLRejectsEventVersionOfAnotherCompany(): void
    {
        Queue::fake();
        $otherCompany = Companies::where('id', '!=', $this->company->getId())->firstOrFail();
        $foreign = $this->createEventVersion($this->createEvent($otherCompany), $otherCompany);

        $response = $this->importMutation($foreign->getId(), [['email' => $this->uniqueEmail()]]);

        $this->assertNotNull($response->json('errors'));
        Queue::assertNotPushed(ImportEventParticipantsJob::class);
    }

    private function runJob(array $rows, int $codeLength = 8, bool $reissue = false): void
    {
        new ImportEventParticipantsJob(
            $this->apps,
            $this->user,
            $this->eventVersion,
            $rows,
            codeLength: $codeLength,
            reissue: $reissue
        )->handle();
    }

    private function importMutation(int $eventVersionId, array $rows): TestResponse
    {
        return $this->graphQL('
            mutation importEventParticipants($input: ImportEventParticipantsInput!) {
                importEventParticipants(input: $input) {
                    queued
                    message
                }
            }
        ', [
            'input' => [
                'event_version_id' => $eventVersionId,
                'rows' => $rows,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);
    }

    private function allPasses()
    {
        return ParticipantPass::where('event_version_id', $this->eventVersion->getId());
    }

    private function activePasses()
    {
        return $this->allPasses()->whereNull('revoked_at')->notDeleted();
    }

    private function uniqueEmail(): string
    {
        return 'guest-' . uniqid() . '@example.com';
    }
}
