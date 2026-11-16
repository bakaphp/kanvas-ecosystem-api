<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Channels\KanvasDatabase;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Enums\PushProviderEnum;
use Kanvas\Notifications\Models\UsersNotificationsSettings;
use Kanvas\Notifications\Templates\Blank;
use NotificationChannels\Expo\ExpoChannel;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the provider is an app setting, which writes to Redis shared by every paratest process.
 */
#[Group('serial')]
final class PushProviderTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->del(AppSettingsEnums::PUSH_PROVIDER->getValue());
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(AppSettingsEnums::PUSH_PROVIDER->getValue());

        parent::tearDown();
    }

    private function makeNotification(array $via): Blank
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $company->getId())
            ->create();

        $notification = new Blank(
            templateName: 'push-provider-test',
            data: [
                'company' => $company,
                'app' => $this->kanvasApp,
                'user' => $user,
            ],
            via: $via,
            entity: $lead
        );
        $notification->setType('push-provider-test');

        return $notification;
    }

    private function saveUserSetting(Blank $notification, array $channels): void
    {
        UsersNotificationsSettings::updateOrCreate([
            'users_id' => auth()->user()->getId(),
            'apps_id' => $this->kanvasApp->getId(),
            'notifications_types_id' => $notification->getType()->getId(),
        ], [
            'is_enabled' => 1,
            'channels' => $channels,
        ]);
    }

    public function testPushAndExpoCollapseToOneSignalWhenNoProviderIsSet(): void
    {
        $notification = $this->makeNotification(['push', 'expo', 'database']);

        $this->assertSame(
            [OneSignalNotificationChannel::class, KanvasDatabase::class],
            $notification->via(auth()->user())
        );
    }

    public function testPushIsDeliveredThroughExpoWhenTheAppUsesExpo(): void
    {
        $this->kanvasApp->set(AppSettingsEnums::PUSH_PROVIDER->getValue(), 'expo');

        $this->assertSame(
            [ExpoChannel::class, KanvasDatabase::class],
            $this->makeNotification(['push', 'database'])->via(auth()->user())
        );
        $this->assertSame(
            [ExpoChannel::class],
            $this->makeNotification(['push', 'expo'])->via(auth()->user())
        );
    }

    public function testChannelClassNamesCollapseToo(): void
    {
        $notification = $this->makeNotification([
            OneSignalNotificationChannel::class,
            ExpoChannel::class,
        ]);

        $this->assertSame([OneSignalNotificationChannel::class], $notification->via(auth()->user()));
    }

    public function testAnUnknownProviderValueFallsBackToOneSignal(): void
    {
        $this->kanvasApp->set(AppSettingsEnums::PUSH_PROVIDER->getValue(), 'firebase');

        $this->assertSame(PushProviderEnum::ONESIGNAL, PushProviderEnum::fromApp($this->kanvasApp));
    }

    public function testAUserWhoOnlyEnabledTheExpoChannelStillGetsPush(): void
    {
        $notification = $this->makeNotification(['push', 'database']);
        $this->saveUserSetting($notification, [NotificationChannelEnum::EXPO->value]);

        $this->assertSame([OneSignalNotificationChannel::class], $notification->via(auth()->user()));
    }

    public function testAUserWhoEnabledNoPushChannelGetsNoPush(): void
    {
        $this->kanvasApp->set(AppSettingsEnums::PUSH_PROVIDER->getValue(), 'expo');

        $notification = $this->makeNotification(['push', 'expo', 'database']);
        $this->saveUserSetting($notification, [NotificationChannelEnum::DATABASE->value]);

        $this->assertSame([KanvasDatabase::class], $notification->via(auth()->user()));
    }
}
