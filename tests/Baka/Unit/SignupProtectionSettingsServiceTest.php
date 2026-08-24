<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Kanvas\Auth\Services\SignupProtectionSettingsService;
use Tests\Stubs\Auth\InMemorySettingsApp;
use Tests\TestCase;

class SignupProtectionSettingsServiceTest extends TestCase
{
    private function set(string $key, mixed $value): SignupProtectionSettingsService
    {
        return new SignupProtectionSettingsService(InMemorySettingsApp::withSettings([$key => $value]));
    }

    private function settings(): SignupProtectionSettingsService
    {
        return new SignupProtectionSettingsService(InMemorySettingsApp::withSettings());
    }

    public function testUnsetKeysFallBackToTheDefaults(): void
    {
        $settings = $this->settings();

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

    public function testAlertRecipientsFallBackToThePlatformList(): void
    {
        config(['kanvas.signup_anomaly.alert_emails' => 'ops@example-corp.com, security@example-corp.com']);

        $this->assertSame(
            ['ops@example-corp.com', 'security@example-corp.com'],
            $this->settings()->anomalyAlertEmails()
        );

        $this->assertSame(
            ['app-owner@example-corp.com'],
            $this->set('signup_anomaly_alert_emails', 'app-owner@example-corp.com')->anomalyAlertEmails()
        );
    }

    public function testRecipientsAcceptAnySeparatorAndDropInvalidEntries(): void
    {
        $settings = $this->set(
            'signup_anomaly_alert_emails',
            "ops@example-corp.com;  security@example-corp.com\nnot-an-email,  \n"
        );

        $this->assertSame(['ops@example-corp.com', 'security@example-corp.com'], $settings->anomalyAlertEmails());
    }

    public function testSentryReportingIsOnByDefault(): void
    {
        config(['kanvas.signup_anomaly.sentry_enabled' => true]);

        $this->assertTrue($this->settings()->sentryReportingEnabled());
    }

    public function testThePlatformSwitchTurnsSentryOffEverywhere(): void
    {
        config(['kanvas.signup_anomaly.sentry_enabled' => false]);

        $this->assertFalse($this->settings()->sentryReportingEnabled());
    }

    public function testAnAppCanOptBackInWhileThePlatformSwitchIsOff(): void
    {
        config(['kanvas.signup_anomaly.sentry_enabled' => false]);

        $this->assertTrue($this->set('signup_abuse_sentry_enabled', '1')->sentryReportingEnabled());
    }

    public function testAnAppCanBeSilencedWhileThePlatformSwitchIsOn(): void
    {
        config(['kanvas.signup_anomaly.sentry_enabled' => true]);

        $this->assertFalse($this->set('signup_abuse_sentry_enabled', '0')->sentryReportingEnabled());
        $this->assertFalse($this->set('signup_abuse_sentry_enabled', 'false')->sentryReportingEnabled());
    }

    public function testNoRecipientsAnywhereYieldsAnEmptyList(): void
    {
        config(['kanvas.signup_anomaly.alert_emails' => null]);

        $this->assertSame([], $this->settings()->anomalyAlertEmails());
    }
}
