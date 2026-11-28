<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications\Mailings;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Audiences\AllAppUsersAudience;
use Kanvas\Notifications\Mailings\Services\AppUserMailingEligibilityService;
use Kanvas\Notifications\Mailings\Services\MailingAudienceService;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesActiveUsers;

class AppUserMailingEligibilityTest extends TestCase
{
    use DatabaseTransactions;
    use MakesActiveUsers;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private function partition(array $users): array
    {
        return new AppUserMailingEligibilityService(app(Apps::class))->partition(
            array_map(fn (Users $user): int => $user->getId(), $users),
        );
    }

    public function testUsersAreExcludedByReasonAndCounted(): void
    {
        $eligible = Users::factory()->create();
        $inactive = Users::factory()->create(['user_active' => 0]);
        $banned = Users::factory()->create(['banned' => 1]);
        $noEmail = Users::factory()->create(['email' => '']);
        $deleted = Users::factory()->create(['is_deleted' => 1]);
        $marketingOff = Users::factory()->create();
        $mutedAll = Users::factory()->create();
        $marketingOn = Users::factory()->create();
        $this->saveNotificationSetting(
            $marketingOff,
            AppUserMailingEligibilityService::NOTIFICATION_TYPE,
            false,
            $this->mailChannel(),
        );
        $this->muteEverything($mutedAll);
        $this->saveNotificationSetting(
            $marketingOn,
            AppUserMailingEligibilityService::NOTIFICATION_TYPE,
            true,
            $this->mailChannel(),
        );

        $result = $this->partition([
            $eligible,
            $inactive,
            $banned,
            $noEmail,
            $deleted,
            $marketingOff,
            $mutedAll,
            $marketingOn,
        ]);

        $this->assertSame([$eligible->getId(), $marketingOn->getId()], $result['eligible']);
        $this->assertSame(
            [
                'unknown_or_deleted' => 1,
                'inactive' => 1,
                'banned' => 1,
                'no_email' => 1,
                'marketing_disabled' => 1,
                'muted_all' => 1,
            ],
            $result['excluded'],
        );
    }

    public function testDisablingOneNotificationTypeIsNotMutingEverything(): void
    {
        $app = app(Apps::class);
        $user = Users::factory()->create();
        $this->saveNotificationSetting(Users::factory()->create(), 'other-' . uniqid(), true, $this->mailChannel());
        $this->saveNotificationSetting($user, 'order-updates-' . uniqid(), false, $this->mailChannel());
        $published = NotificationTypes::query()
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->where('is_published', 1)
            ->count();

        $result = $this->partition([$user]);

        $this->assertGreaterThan(1, $published);
        $this->assertSame([$user->getId()], $result['eligible']);
        $this->assertSame(0, $result['excluded']['muted_all']);
    }

    public function testAMarketingSettingWithoutTheMailChannelExcludesTheUser(): void
    {
        $smsOnly = Users::factory()->create();
        $this->saveNotificationSetting(
            $smsOnly,
            AppUserMailingEligibilityService::NOTIFICATION_TYPE,
            true,
            [5],
        );

        $result = $this->partition([$smsOnly]);

        $this->assertSame([], $result['eligible']);
        $this->assertSame(1, $result['excluded']['marketing_disabled']);
    }

    public function testASampleOfEmailsIsMasked(): void
    {
        $user = Users::factory()->create(['email' => 'jane.doe@example.test']);

        $sample = new MailingAudienceService(app(Apps::class), null)->maskedSample([$user->getId()]);

        $this->assertSame(['ja***@example.test'], $sample);
    }

    public function testTheAllAppUsersAudienceListsTheAppsUsersAndHonorsTheCreationWindow(): void
    {
        $app = app(Apps::class);
        $member = auth()->user();
        $audience = new AllAppUsersAudience();

        $everyone = $audience->resolve($app, null, []);
        $none = $audience->resolve($app, null, ['created_after' => '2999-01-01 00:00:00']);
        $nobodyBefore = $audience->resolve($app, null, ['created_before' => '2000-01-01 00:00:00']);

        $this->assertContains($member->getId(), $everyone);
        $this->assertSame([], $none);
        $this->assertSame([], $nobodyBefore);
    }
}
