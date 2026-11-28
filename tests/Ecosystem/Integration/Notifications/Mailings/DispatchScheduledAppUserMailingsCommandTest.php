<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications\Mailings;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailJob;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Tests\TestCase;

class DispatchScheduledAppUserMailingsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private function mailing(
        Carbon $scheduledAt,
        AppUserMailingStatusEnum $status = AppUserMailingStatusEnum::SCHEDULED,
    ): AppUserMailing {
        $mailing = new AppUserMailing();
        $mailing->apps_id = app(Apps::class)->getId();
        $mailing->companies_id = 0;
        $mailing->users_id = auth()->user()->getId();
        $mailing->audience = AppUserMailingAudienceEnum::ACTIVE_USERS;
        $mailing->template_name = 'any-template';
        $mailing->criteria = ['timezone' => 'UTC'];
        $mailing->status = $status;
        $mailing->scheduled_at = $scheduledAt;
        $mailing->total_eligible = 1;
        $mailing->recipient_ids = [1];
        $mailing->saveOrFail();

        return $mailing;
    }

    private function dispatchedFor(AppUserMailing $mailing): int
    {
        return Bus::dispatched(
            SendAppUsersEmailJob::class,
            fn (SendAppUsersEmailJob $job): bool => $job->mailing->is($mailing)
        )->count();
    }

    public function testOnlyDueScheduledMailingsAreQueuedAndOnlyOnce(): void
    {
        Bus::fake();
        $due = $this->mailing(Carbon::now()->subMinute());
        $future = $this->mailing(Carbon::now()->addHour());
        $cancelled = $this->mailing(Carbon::now()->subMinute(), AppUserMailingStatusEnum::CANCELLED);
        $sending = $this->mailing(Carbon::now()->subMinute(), AppUserMailingStatusEnum::SENDING);

        $this->artisan('kanvas:dispatch-scheduled-app-user-mailings')->assertSuccessful();
        $this->artisan('kanvas:dispatch-scheduled-app-user-mailings')->assertSuccessful();

        $this->assertSame(1, $this->dispatchedFor($due));
        $this->assertSame(0, $this->dispatchedFor($future));
        $this->assertSame(0, $this->dispatchedFor($cancelled));
        $this->assertSame(0, $this->dispatchedFor($sending));
        $this->assertSame(AppUserMailingStatusEnum::QUEUED, $due->refresh()->status);
        $this->assertSame(AppUserMailingStatusEnum::SCHEDULED, $future->refresh()->status);
        $this->assertSame(AppUserMailingStatusEnum::CANCELLED, $cancelled->refresh()->status);
    }

    public function testAMailingClaimedByAnotherRunIsNotDispatchedAgain(): void
    {
        $mailing = $this->mailing(Carbon::now()->subMinute());
        $stale = AppUserMailing::query()->findOrFail($mailing->getId());

        $this->assertTrue($mailing->claimForDispatch());
        $this->assertFalse($stale->claimForDispatch());
    }

    public function testACancelledMailingCannotBeClaimedAndAClaimedOneCannotBeCancelled(): void
    {
        $cancelled = $this->mailing(Carbon::now()->subMinute());
        $claimed = $this->mailing(Carbon::now()->subMinute());

        $this->assertTrue($cancelled->cancelIfScheduled());
        $this->assertFalse($cancelled->claimForDispatch());
        $this->assertTrue($claimed->claimForDispatch());
        $this->assertFalse($claimed->cancelIfScheduled());
    }
}
