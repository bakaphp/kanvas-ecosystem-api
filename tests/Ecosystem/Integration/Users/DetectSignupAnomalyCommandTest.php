<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Notifications\SignupAnomalyNotification;
use Tests\TestCase;

class DetectSignupAnomalyCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    public function testASpikeAboveTheAppBaselineIsReported(): void
    {
        $app = app(Apps::class);
        $app->set('signup_anomaly_floor', 1);
        $app->set('signup_anomaly_multiplier', 0);

        try {
            $this->createUser();

            $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId(), '--dry-run' => true])
                ->expectsOutputToContain('signups last hour')
                ->assertSuccessful();
        } finally {
            $app->del('signup_anomaly_floor');
            $app->del('signup_anomaly_multiplier');
        }
    }

    public function testNormalTrafficStaysBelowTheFloorAndIsSilent(): void
    {
        $app = app(Apps::class);
        $app->set('signup_anomaly_floor', 100000);

        try {
            $this->createUser();

            $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId(), '--dry-run' => true])
                ->doesntExpectOutputToContain('signups last hour')
                ->assertSuccessful();
        } finally {
            $app->del('signup_anomaly_floor');
        }
    }

    public function testMissingRecipientsFallBackToSentryRatherThanThrowing(): void
    {
        $app = app(Apps::class);
        $app->set('signup_anomaly_floor', 1);
        $app->set('signup_anomaly_multiplier', 0);
        config(['kanvas.signup_anomaly.alert_emails' => null]);

        try {
            $this->createUser();

            $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId()])
                ->expectsOutputToContain('No alert recipients configured')
                ->assertSuccessful();
        } finally {
            $app->del('signup_anomaly_floor');
            $app->del('signup_anomaly_multiplier');
        }
    }

    public function testAConfiguredRecipientGetsTheSpikeEmail(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $app->set('signup_anomaly_floor', 1);
        $app->set('signup_anomaly_multiplier', 0);
        config(['kanvas.signup_anomaly.alert_emails' => 'ops@example-corp.com']);

        try {
            $this->createUser();

            $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId()])->assertSuccessful();

            Notification::assertSentOnDemand(
                SignupAnomalyNotification::class,
                static fn (SignupAnomalyNotification $notification, array $channels, object $notifiable): bool =>
                    $channels === ['mail']
                    && $notifiable->routes['mail'] === ['ops@example-corp.com']
            );
        } finally {
            $app->del('signup_anomaly_floor');
            $app->del('signup_anomaly_multiplier');
        }
    }

    public function testTheCooldownStopsARepeatEmailWithinTheWindow(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $app->set('signup_anomaly_floor', 1);
        $app->set('signup_anomaly_multiplier', 0);
        config(['kanvas.signup_anomaly.alert_emails' => 'ops@example-corp.com']);

        try {
            $this->createUser();

            $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId()])->assertSuccessful();
            $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId()])->assertSuccessful();

            Notification::assertSentOnDemandTimes(SignupAnomalyNotification::class, 1);
        } finally {
            $app->del('signup_anomaly_floor');
            $app->del('signup_anomaly_multiplier');
        }
    }
}
