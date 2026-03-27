<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Enums\UserConfigEnum;
use Tests\TestCase;

class RunVerifyTwoFactorAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();

        $app->del('DISABLE_TWO_FACTOR_AUTH');
        $user->del(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value);
        $user->setCurrentDeviceId(null);
    }

    public function testReturnsFalseWhenTwoFactorIsNotDisabled(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        // DISABLE_TWO_FACTOR_AUTH is not set (falsy), so 2FA check is skipped
        $app->del('DISABLE_TWO_FACTOR_AUTH');

        $this->assertFalse($user->runVerifyTwoFactorAuth($app));
    }

    public function testReturnsFalseWhenPhoneRecentlyVerifiedWithoutDeviceCookie(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $appProfile = $user->getAppProfile($app);

        $app->set('DISABLE_TWO_FACTOR_AUTH', true);
        $appProfile->update(['phone_verified_at' => now()->subDays(3)->toDateTimeString()]);
        $user->del(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value);

        $this->assertFalse($user->runVerifyTwoFactorAuth($app));
    }

    public function testReturnsTrueWhenPhoneVerifiedOverSevenDaysAgoWithoutDeviceCookie(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $appProfile = $user->getAppProfile($app);

        $app->set('DISABLE_TWO_FACTOR_AUTH', true);
        $appProfile->update(['phone_verified_at' => now()->subDays(10)->toDateTimeString()]);
        $user->del(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value);

        $this->assertTrue($user->runVerifyTwoFactorAuth($app));
    }

    public function testReturnsFalseWhenDeviceCookieSetAndPhoneVerifiedWithin30Days(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $appProfile = $user->getAppProfile($app);

        $app->set('DISABLE_TWO_FACTOR_AUTH', true);
        $appProfile->update(['phone_verified_at' => now()->subDays(15)->toDateTimeString()]);
        $user->set(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value, true);

        $this->assertFalse($user->runVerifyTwoFactorAuth($app));
    }

    public function testReturnsTrueWhenDeviceCookieSetButPhoneVerifiedOver30DaysAgo(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $appProfile = $user->getAppProfile($app);

        $app->set('DISABLE_TWO_FACTOR_AUTH', true);
        $appProfile->update(['phone_verified_at' => now()->subDays(35)->toDateTimeString()]);
        $user->set(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value, true);

        $this->assertTrue($user->runVerifyTwoFactorAuth($app));
    }

    public function testUsesDeviceSpecificKeyWhenDeviceIdIsSet(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $appProfile = $user->getAppProfile($app);

        $app->set('DISABLE_TWO_FACTOR_AUTH', true);
        $appProfile->update(['phone_verified_at' => now()->subDays(15)->toDateTimeString()]);

        $deviceId = 'test-device-123';
        $user->setCurrentDeviceId($deviceId);

        // Set the device-specific key
        $deviceKey = UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value . '-' . $deviceId;
        $user->set($deviceKey, true);

        $this->assertFalse($user->runVerifyTwoFactorAuth($app));

        // Clean up
        $user->del($deviceKey);
    }

    public function testReturnsTrueWhenNoPhoneVerificationAtAll(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $appProfile = $user->getAppProfile($app);

        $app->set('DISABLE_TWO_FACTOR_AUTH', true);
        $appProfile->update(['phone_verified_at' => null]);
        $user->del(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value);

        $this->assertTrue($user->runVerifyTwoFactorAuth($app));
    }

    public function testReturnsTrueWhenDeviceCookieSetButNoPhoneVerification(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $appProfile = $user->getAppProfile($app);

        $app->set('DISABLE_TWO_FACTOR_AUTH', true);
        $appProfile->update(['phone_verified_at' => null]);
        $user->set(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value, true);

        $this->assertTrue($user->runVerifyTwoFactorAuth($app));
    }
}
