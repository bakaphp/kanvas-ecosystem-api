<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Tests\TestCase;

final class ValidateAllPeopleEmailsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    private Apps $kanvasApp;
    private Apps $originalApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApp = app(Apps::class);
        // An app with no MAILGUN_API_KEY of its own, which the shared one cannot promise.
        $this->kanvasApp = $this->dedicatedApp();
        $this->company = Companies::factory()->create();

        Http::fake(['*' => Http::response([])]);
    }

    protected function tearDown(): void
    {
        app()->instance(Apps::class, $this->originalApp);

        parent::tearDown();
    }

    public function testAnAppOnThePlatformMailgunKeyIsValidated(): void
    {
        config(['services.mailgun.secret' => 'key-platform']);

        $this->artisan('kanvas:guild-mailgun-email-validate', $this->tenant())
            ->expectsOutputToContain('validated')
            ->assertSuccessful();
    }

    public function testNothingRunsWithoutAnyMailgunKey(): void
    {
        config(['services.mailgun.secret' => null]);

        $this->artisan('kanvas:guild-mailgun-email-validate', $this->tenant())
            ->expectsOutputToContain('No Mailgun API key configured')
            ->assertFailed();
    }

    /**
     * @return array{app_id: int, company_id: int}
     */
    private function tenant(): array
    {
        return ['app_id' => $this->kanvasApp->getId(), 'company_id' => $this->company->getId()];
    }

    private function dedicatedApp(): Apps
    {
        $uniqueId = uniqid('mailgun', true);

        $app = new Apps();
        $app->name = 'Mailgun Validate Test ' . $uniqueId;
        $app->url = 'https://' . $uniqueId . '.example.com';
        $app->domain = $uniqueId . '.example.com';
        $app->description = 'Email validation command test app';
        $app->is_actived = 1;
        $app->ecosystem_auth = 0;
        $app->payments_active = 0;
        $app->is_public = 0;
        $app->domain_based = 0;
        $app->saveOrFail();

        return $app;
    }
}
