<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications\Mailings;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailJob;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class MailAllAppUsersCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private function arguments(array $extra = []): array
    {
        return [
            'apps_id' => app(Apps::class)->getId(),
            'email_template_name' => 'any-template',
            'subject' => 'Hello app',
            ...$extra,
        ];
    }

    private function latestAppWideMailing(): ?AppUserMailing
    {
        return AppUserMailing::query()
            ->where('apps_id', app(Apps::class)->getId())
            ->where('audience', AppUserMailingAudienceEnum::APP_USERS->value)
            ->latest('id')
            ->first();
    }

    public function testTheTestEmailGoesToThatUserOnlyThroughTheSharedFactory(): void
    {
        Notification::fake();
        Bus::fake();
        $user = Users::factory()->create(['firstname' => 'Grace', 'lastname' => 'Hopper']);
        $before = AppUserMailing::query()->count();

        $this->artisan('kanvas:mail-notification-to-all-app-users', $this->arguments(['--test-email' => $user->email]))
            ->assertSuccessful();

        Notification::assertSentTo(
            $user,
            Blank::class,
            fn (Blank $notification): bool => $notification->getSubject() === 'Hello app'
                && $notification->getData()['name'] === 'Grace Hopper'
        );
        $this->assertSame($before, AppUserMailing::query()->count());
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testProductionQueuesAnAppWideMailingThroughTheSharedAction(): void
    {
        Bus::fake();
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->artisan('kanvas:mail-notification-to-all-app-users', $this->arguments(['--production' => true]))
                ->expectsOutputToContain('queued on the app-mailing queue')
                ->assertSuccessful();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }

        $mailing = $this->latestAppWideMailing();
        $this->assertNotNull($mailing);
        $this->assertSame(0, $mailing->companies_id);
        $this->assertNull($mailing->users_id);
        $this->assertSame('any-template', $mailing->template_name);
        $this->assertSame('Hello app', $mailing->subject);
        $this->assertSame(AppUserMailingStatusEnum::QUEUED, $mailing->status);
        $this->assertContains(auth()->user()->getId(), $mailing->recipient_ids);
        $this->assertSame(count($mailing->recipient_ids), $mailing->total_eligible);
        Bus::assertDispatched(
            SendAppUsersEmailJob::class,
            fn (SendAppUsersEmailJob $job): bool => $job->mailing->is($mailing)
        );
    }

    public function testWithoutTheProductionFlagNothingIsQueued(): void
    {
        Bus::fake();
        $before = AppUserMailing::query()->count();

        $this->artisan('kanvas:mail-notification-to-all-app-users', $this->arguments())
            ->expectsOutputToContain('only send emails to all users in production')
            ->assertSuccessful();

        $this->assertSame($before, AppUserMailing::query()->count());
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }
}
