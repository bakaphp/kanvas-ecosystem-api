<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\SignupProtectionSettingsService;
use Tests\TestCase;

class SignupProtectionSettingsServiceTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $touched = [];

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->touched as $key) {
            $app->del($key);
        }

        parent::tearDown();
    }

    private function set(string $key, mixed $value): SignupProtectionSettingsService
    {
        $app = app(Apps::class);
        $app->set($key, $value);
        $this->touched[] = $key;

        return new SignupProtectionSettingsService($app);
    }

    public function testUnsetKeysFallBackToTheDefaults(): void
    {
        $settings = new SignupProtectionSettingsService(app(Apps::class));

        $this->assertSame(SignupProtectionSettingsService::DEFAULT_PREFIX_LIMIT, $settings->prefixLimit());
        $this->assertSame(SignupProtectionSettingsService::DEFAULT_PREFIX_WINDOW_SECONDS, $settings->prefixWindowSeconds());
        $this->assertSame(SignupProtectionSettingsService::DEFAULT_MAILBOX_LIMIT, $settings->mailboxLimit());
        $this->assertSame(SignupProtectionSettingsService::DEFAULT_MAILBOX_WINDOW_SECONDS, $settings->mailboxWindowSeconds());
        $this->assertSame(SignupProtectionSettingsService::DEFAULT_ANOMALY_MULTIPLIER, $settings->anomalyMultiplier());
        $this->assertSame(SignupProtectionSettingsService::DEFAULT_ANOMALY_FLOOR, $settings->anomalyFloor());
        $this->assertSame(SignupProtectionSettingsService::DEFAULT_ANOMALY_BASELINE_DAYS, $settings->anomalyBaselineDays());
        $this->assertSame(SignupProtectionSettingsService::DEFAULT_ANOMALY_COOLDOWN_SECONDS, $settings->anomalyCooldownSeconds());
    }

    public function testAppSettingsOverrideTheDefaults(): void
    {
        $this->assertSame(2, $this->set('signup_prefix_burst_limit', 2)->prefixLimit());
        $this->assertSame(120, $this->set('signup_prefix_burst_window', 120)->prefixWindowSeconds());
        $this->assertSame(30, $this->set('signup_anomaly_baseline_days', 30)->anomalyBaselineDays());
        $this->assertSame(3600, $this->set('signup_anomaly_cooldown', 3600)->anomalyCooldownSeconds());
    }

    public function testAClearedSettingFallsBackRatherThanReadingAsZero(): void
    {
        $this->assertSame(
            SignupProtectionSettingsService::DEFAULT_ANOMALY_FLOOR,
            $this->set('signup_anomaly_floor', '')->anomalyFloor()
        );
    }

    public function testALimitOfZeroIsHonouredSoARuleCanBeDisabled(): void
    {
        $this->assertSame(0, $this->set('signup_prefix_burst_limit', 0)->prefixLimit());
        $this->assertSame(0, $this->set('signup_mailbox_limit', 0)->mailboxLimit());
    }

    public function testWindowsAreFlooredSoACounterCanNeverExpireInstantly(): void
    {
        $this->assertSame(60, $this->set('signup_prefix_burst_window', 0)->prefixWindowSeconds());
        $this->assertSame(60, $this->set('signup_mailbox_window', 5)->mailboxWindowSeconds());
        $this->assertSame(1, $this->set('signup_anomaly_baseline_days', 0)->anomalyBaselineDays());
    }

    public function testTheWebhookFallsBackToThePlatformConfig(): void
    {
        config(['kanvas.signup_anomaly.slack_webhook' => 'https://hooks.slack.test/platform']);

        $this->assertSame(
            'https://hooks.slack.test/platform',
            new SignupProtectionSettingsService(app(Apps::class))->anomalySlackWebhook()
        );

        $this->assertSame(
            'https://hooks.slack.test/app',
            $this->set('signup_anomaly_slack_webhook', 'https://hooks.slack.test/app')->anomalySlackWebhook()
        );
    }
}
