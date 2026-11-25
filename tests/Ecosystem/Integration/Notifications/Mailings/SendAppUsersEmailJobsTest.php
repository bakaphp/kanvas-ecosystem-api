<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications\Mailings;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailChunkJob;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailJob;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Kanvas\Notifications\Mailings\Services\AppUserMailingEligibilityService;
use Kanvas\Notifications\Mailings\Services\SentRecipientsLedger;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\MakesActiveUsers;

class SendAppUsersEmailJobsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesActiveUsers;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private function mailing(
        array $recipientIds,
        AppUserMailingStatusEnum $status = AppUserMailingStatusEnum::SENDING,
    ): AppUserMailing {
        $mailing = new AppUserMailing();
        $mailing->apps_id = app(Apps::class)->getId();
        $mailing->companies_id = 0;
        $mailing->users_id = auth()->user()->getId();
        $mailing->audience = AppUserMailingAudienceEnum::ACTIVE_USERS;
        $mailing->template_name = 'any-template';
        $mailing->subject = 'Hello';
        $mailing->criteria = ['timezone' => 'UTC'];
        $mailing->status = $status;
        $mailing->total_eligible = count($recipientIds);
        $mailing->recipient_ids = $recipientIds;
        $mailing->saveOrFail();

        return $mailing;
    }

    private function chunk(AppUserMailing $mailing, array $userIds): SendAppUsersEmailChunkJob
    {
        return new SendAppUsersEmailChunkJob(app(Apps::class), $mailing, $userIds);
    }

    public function testTheChunkJobMailsEveryUserCountsThemAndCompletesTheRecord(): void
    {
        Notification::fake();
        $first = Users::factory()->create(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $second = Users::factory()->create();
        $mailing = $this->mailing([$first->getId(), $second->getId()]);

        $this->chunk($mailing, [$first->getId(), $second->getId()])->handle();

        Notification::assertSentTo(
            [$first, $second],
            Blank::class,
            fn (Blank $notification): bool => $notification->getSubject() === 'Hello'
        );
        Notification::assertSentTo(
            $first,
            Blank::class,
            fn (Blank $notification): bool => $notification->getData()['name'] === 'Ada Lovelace'
        );
        $mailing->refresh();
        $this->assertSame(2, $mailing->sent_count);
        $this->assertSame(0, $mailing->failed_count);
        $this->assertSame(AppUserMailingStatusEnum::COMPLETED, $mailing->status);
        $this->assertNotNull($mailing->completed_at);
    }

    public function testAMissingUserIsCountedAsFailedAndTheRestStillGoOut(): void
    {
        Notification::fake();
        $user = Users::factory()->create();
        $mailing = $this->mailing([$user->getId(), 999999999]);

        $this->chunk($mailing, [$user->getId(), 999999999])->handle();

        Notification::assertSentTo($user, Blank::class);
        $mailing->refresh();
        $this->assertSame(1, $mailing->sent_count);
        $this->assertSame(1, $mailing->failed_count);
        $this->assertSame(AppUserMailingStatusEnum::COMPLETED, $mailing->status);
    }

    public function testTheRecordStaysSendingUntilEveryChunkReported(): void
    {
        Notification::fake();
        $first = Users::factory()->create();
        $second = Users::factory()->create();
        $mailing = $this->mailing([$first->getId(), $second->getId()]);

        $this->chunk($mailing, [$first->getId()])->handle();

        $mailing->refresh();
        $this->assertSame(1, $mailing->sent_count);
        $this->assertSame(AppUserMailingStatusEnum::SENDING, $mailing->status);

        $this->chunk($mailing, [$second->getId()])->handle();

        $this->assertSame(AppUserMailingStatusEnum::COMPLETED, $mailing->refresh()->status);
        $this->assertSame(2, $mailing->sent_count);
    }

    public function testASendThatThrowsCountsTheRecipientAsFailedAndReportsOncePerChunk(): void
    {
        $handler = Mockery::spy(ExceptionHandler::class);
        $this->app->instance(ExceptionHandler::class, $handler);
        Notification::shouldReceive('sendNow')->twice()->andThrow(new RuntimeException('smtp down'));
        $first = Users::factory()->create();
        $second = Users::factory()->create();
        $mailing = $this->mailing([$first->getId(), $second->getId()]);

        $this->chunk($mailing, [$first->getId(), $second->getId()])->handle();

        $handler->shouldHaveReceived('report')->once();
        $mailing->refresh();
        $this->assertSame(0, $mailing->sent_count);
        $this->assertSame(2, $mailing->failed_count);
        $this->assertSame(AppUserMailingStatusEnum::FAILED, $mailing->status);
    }

    public function testARetryDoesNotMailTheRecipientsAlreadyReached(): void
    {
        Notification::fake();
        $reached = Users::factory()->create();
        $pending = Users::factory()->create();
        $mailing = $this->mailing([$reached->getId(), $pending->getId()]);
        new SentRecipientsLedger($mailing)->markSent($reached->getId());

        $this->chunk($mailing, [$reached->getId(), $pending->getId()])->handle();

        Notification::assertNotSentTo($reached, Blank::class);
        Notification::assertSentTo($pending, Blank::class);
        $mailing->refresh();
        $this->assertSame(2, $mailing->sent_count);
        $this->assertSame(0, $mailing->failed_count);
        $this->assertSame(AppUserMailingStatusEnum::COMPLETED, $mailing->status);
    }

    public function testTheFinalFailureOfAChunkClosesTheRecordWithWhatWasReached(): void
    {
        $reached = Users::factory()->create();
        $lost = Users::factory()->create();
        $mailing = $this->mailing([$reached->getId(), $lost->getId()]);
        new SentRecipientsLedger($mailing)->markSent($reached->getId());

        $this->chunk($mailing, [$reached->getId(), $lost->getId()])->failed(new RuntimeException('worker died'));

        $mailing->refresh();
        $this->assertSame(1, $mailing->sent_count);
        $this->assertSame(1, $mailing->failed_count);
        $this->assertSame(AppUserMailingStatusEnum::COMPLETED, $mailing->status);
        $this->assertSame('worker died', $mailing->failed_reason);
    }

    public function testAChunkThatReachedNobodyFailsTheRecord(): void
    {
        $lost = Users::factory()->create();
        $mailing = $this->mailing([$lost->getId()]);

        $this->chunk($mailing, [$lost->getId()])->failed(new RuntimeException('worker died'));

        $mailing->refresh();
        $this->assertSame(0, $mailing->sent_count);
        $this->assertSame(1, $mailing->failed_count);
        $this->assertSame(AppUserMailingStatusEnum::FAILED, $mailing->status);
    }

    public function testAUserWhoTurnedMarketingOffIsNotMailedByTheChunk(): void
    {
        Notification::fake();
        $off = Users::factory()->create();
        $on = Users::factory()->create();
        $this->saveNotificationSetting(
            $off,
            AppUserMailingEligibilityService::NOTIFICATION_TYPE,
            false,
            $this->mailChannel(),
        );
        $mailing = $this->mailing([$off->getId(), $on->getId()]);

        $this->chunk($mailing, [$off->getId(), $on->getId()])->handle();

        Notification::assertNotSentTo($off, Blank::class);
        Notification::assertSentTo($on, Blank::class);
    }

    public function testTheOrchestratorChunksTheFrozenAudienceOnTheDedicatedQueue(): void
    {
        Bus::fake([SendAppUsersEmailChunkJob::class]);
        $frozen = [101, 102, 103];
        $mailing = $this->mailing($frozen, AppUserMailingStatusEnum::QUEUED);

        $job = new SendAppUsersEmailJob(app(Apps::class), $mailing);
        $job->handle();

        $this->assertSame('app-mailing', $job->queue);
        Bus::assertDispatchedTimes(SendAppUsersEmailChunkJob::class, 1);
        Bus::assertDispatched(
            SendAppUsersEmailChunkJob::class,
            fn (SendAppUsersEmailChunkJob $chunk): bool => $chunk->userIds === $frozen
                && $chunk->queue === 'app-mailing'
        );
        $mailing->refresh();
        $this->assertSame(AppUserMailingStatusEnum::SENDING, $mailing->status);
        $this->assertSame(3, $mailing->total_eligible);
        $this->assertNotNull($mailing->started_at);
    }

    public function testMoreThanOneHundredRecipientsSplitIntoTwoChunks(): void
    {
        Bus::fake([SendAppUsersEmailChunkJob::class]);
        $mailing = $this->mailing(range(1, 101), AppUserMailingStatusEnum::QUEUED);

        new SendAppUsersEmailJob(app(Apps::class), $mailing)->handle();

        Bus::assertDispatchedTimes(SendAppUsersEmailChunkJob::class, 2);
        Bus::assertDispatched(
            SendAppUsersEmailChunkJob::class,
            fn (SendAppUsersEmailChunkJob $chunk): bool => count($chunk->userIds) === 100
        );
        Bus::assertDispatched(
            SendAppUsersEmailChunkJob::class,
            fn (SendAppUsersEmailChunkJob $chunk): bool => count($chunk->userIds) === 1
        );
    }

    public function testAnEmptyFrozenAudienceCompletesTheRecordWithoutChunks(): void
    {
        Bus::fake([SendAppUsersEmailChunkJob::class]);
        $mailing = $this->mailing([], AppUserMailingStatusEnum::QUEUED);

        new SendAppUsersEmailJob(app(Apps::class), $mailing)->handle();

        Bus::assertNotDispatched(SendAppUsersEmailChunkJob::class);
        $this->assertSame(AppUserMailingStatusEnum::COMPLETED, $mailing->refresh()->status);
    }

    public function testAnOrchestratorFailureMarksTheRecordFailedWithTheReason(): void
    {
        $mailing = $this->mailing([1], AppUserMailingStatusEnum::QUEUED);

        new SendAppUsersEmailJob(app(Apps::class), $mailing)->failed(new RuntimeException('queue exploded'));

        $mailing->refresh();
        $this->assertSame(AppUserMailingStatusEnum::FAILED, $mailing->status);
        $this->assertSame('queue exploded', $mailing->failed_reason);
        $this->assertNotNull($mailing->completed_at);
    }

    public function testTheFrozenRecipientsAreNeverSerialized(): void
    {
        $mailing = $this->mailing([1, 2]);

        $this->assertArrayNotHasKey('recipient_ids', $mailing->toArray());
    }

    public function testAScheduledMailingDropsRecipientsWhoStoppedBeingEligibleBeforeDelivery(): void
    {
        Bus::fake([SendAppUsersEmailChunkJob::class]);
        $stays = Users::factory()->create();
        $banned = Users::factory()->create(['banned' => 1]);
        $gone = Users::factory()->create(['user_active' => 0]);
        $mailing = $this->mailing([$stays->getId(), $banned->getId(), $gone->getId()], AppUserMailingStatusEnum::QUEUED);
        $mailing->scheduled_at = Carbon::now()->subMinute();
        $mailing->total_excluded = 4;
        $mailing->excluded_breakdown = ['banned' => 4];
        $mailing->saveOrFail();

        new SendAppUsersEmailJob(app(Apps::class), $mailing)->handle();

        $mailing->refresh();
        $this->assertSame(1, $mailing->total_eligible);
        $this->assertSame(6, $mailing->total_excluded);
        $this->assertSame(5, $mailing->excluded_breakdown['banned']);
        $this->assertSame(1, $mailing->excluded_breakdown['inactive']);
        $this->assertSame([$stays->getId()], $mailing->recipient_ids);
        Bus::assertDispatched(
            SendAppUsersEmailChunkJob::class,
            fn (SendAppUsersEmailChunkJob $chunk): bool => $chunk->userIds === [$stays->getId()]
        );
    }

    public function testAnImmediateMailingKeepsItsFrozenRecipientsUntouched(): void
    {
        Bus::fake([SendAppUsersEmailChunkJob::class]);
        $banned = Users::factory()->create(['banned' => 1]);
        $mailing = $this->mailing([$banned->getId()], AppUserMailingStatusEnum::QUEUED);

        new SendAppUsersEmailJob(app(Apps::class), $mailing)->handle();

        $this->assertSame(1, $mailing->refresh()->total_eligible);
        Bus::assertDispatched(SendAppUsersEmailChunkJob::class);
    }

    public function testTheOrchestratorLeavesACancelledMailingAlone(): void
    {
        Bus::fake([SendAppUsersEmailChunkJob::class]);
        $mailing = $this->mailing([1], AppUserMailingStatusEnum::CANCELLED);

        new SendAppUsersEmailJob(app(Apps::class), $mailing)->handle();

        Bus::assertNotDispatched(SendAppUsersEmailChunkJob::class);
        $this->assertSame(AppUserMailingStatusEnum::CANCELLED, $mailing->refresh()->status);
        $this->assertNull($mailing->started_at);
    }
}
