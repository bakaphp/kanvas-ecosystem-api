<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
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

    public function testAMissingWebhookIsReportedRatherThanThrowing(): void
    {
        $app = app(Apps::class);
        $app->set('signup_anomaly_floor', 1);
        $app->set('signup_anomaly_multiplier', 0);
        config(['kanvas.signup_anomaly.slack_webhook' => null]);

        try {
            $this->createUser();

            $this->artisan('kanvas:detect-signup-anomaly', ['apps_id' => $app->getId()])
                ->expectsOutputToContain('No Slack webhook configured')
                ->assertSuccessful();
        } finally {
            $app->del('signup_anomaly_floor');
            $app->del('signup_anomaly_multiplier');
        }
    }
}
