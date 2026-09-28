<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Notifications\SignupAnomalyNotification;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

class DetectSignupAnomalyCommandTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    /**
     * A floor of 1 and a multiplier of 0 make any signup at all count as a
     * spike, so a test needs one registration rather than a realistic burst.
     */
    private function alwaysAlerting(): Apps
    {
        return $this->setAppSettings([
            'signup_anomaly_floor' => 1,
            'signup_anomaly_multiplier' => 0,
        ]);
    }

    /**
     * The shared TestCase::createUser() draws from `fake()->email`, which
     * collides with the thousands of Faker addresses previous suite runs left in
     * the database. A uuid keeps every signup this test makes unique.
     */
    private function registerSignup(): void
    {
        new RegisterUsersAction(
            RegisterInput::from([
                'email' => 'spike-' . fake()->unique()->uuid() . '@example-corp.com',
                'password' => fake()->password(8),
                'firstname' => 'Spike',
                'lastname' => 'Tester',
            ])
        )->execute();
    }

    private function runSweep(Apps $app): void
    {
        $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId()])->assertSuccessful();
    }

    public function testASpikeAboveTheAppBaselineIsReported(): void
    {
        $app = $this->alwaysAlerting();
        $this->registerSignup();

        $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId(), '--dry-run' => true])
            ->expectsOutputToContain('signups last hour')
            ->assertSuccessful();
    }

    public function testNormalTrafficStaysBelowTheFloorAndIsSilent(): void
    {
        $app = $this->setAppSettings(['signup_anomaly_floor' => 100000]);
        $this->registerSignup();

        $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId(), '--dry-run' => true])
            ->doesntExpectOutputToContain('signups last hour')
            ->assertSuccessful();
    }

    public function testMissingRecipientsFallBackToSentryRatherThanThrowing(): void
    {
        config(['kanvas.signup_anomaly.alert_emails' => null]);

        $app = $this->alwaysAlerting();
        $this->registerSignup();

        $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId()])
            ->expectsOutputToContain('No alert recipients configured')
            ->assertSuccessful();
    }

    public function testAConfiguredRecipientGetsTheSpikeEmail(): void
    {
        Notification::fake();
        config(['kanvas.signup_anomaly.alert_emails' => 'ops@example-corp.com']);

        $app = $this->alwaysAlerting();
        $this->registerSignup();
        $this->runSweep($app);

        Notification::assertSentOnDemand(
            SignupAnomalyNotification::class,
            static fn (SignupAnomalyNotification $notification, array $channels, object $notifiable): bool =>
                $channels === ['mail'] && $notifiable->routes['mail'] === ['ops@example-corp.com']
        );
    }

    public function testEmailStillGoesOutWithSentryTurnedOff(): void
    {
        Notification::fake();
        config([
            'kanvas.signup_anomaly.alert_emails' => 'ops@example-corp.com',
            'kanvas.signup_anomaly.sentry_enabled' => false,
        ]);

        $app = $this->alwaysAlerting();
        $this->registerSignup();
        $this->runSweep($app);

        Notification::assertSentOnDemandTimes(SignupAnomalyNotification::class, 1);
    }

    public function testTheCooldownStopsARepeatEmailWithinTheWindow(): void
    {
        Notification::fake();
        config(['kanvas.signup_anomaly.alert_emails' => 'ops@example-corp.com']);

        $app = $this->alwaysAlerting();
        $this->registerSignup();
        $this->runSweep($app);
        $this->runSweep($app);

        Notification::assertSentOnDemandTimes(SignupAnomalyNotification::class, 1);
    }
}
