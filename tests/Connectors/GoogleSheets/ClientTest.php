<?php

declare(strict_types=1);

namespace Tests\Connectors\GoogleSheets;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\GoogleSheets\Client;
use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * GOOGLE_SHEETS_CREDENTIALS lives on the app's custom-fields store, which persists outside the
     * ambient test transaction — DatabaseTransactions does NOT roll it back. Since app(Apps::class)
     * resolves the one shared app every test in this suite runs against (including a real, live
     * service-account key in some environments), save/restore it explicitly so this test never
     * clobbers a real credential.
     */
    private mixed $originalCredentials = null;
    private mixed $originalImpersonateUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCredentials = app(Apps::class)->get(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value);
        $this->originalImpersonateUser = app(Apps::class)->get(ConfigurationEnum::IMPERSONATE_USER->value);
    }

    protected function tearDown(): void
    {
        app(Apps::class)->set(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value, $this->originalCredentials);
        app(Apps::class)->set(ConfigurationEnum::IMPERSONATE_USER->value, $this->originalImpersonateUser);

        parent::tearDown();
    }

    private function fakeServiceAccountJson(): string
    {
        return json_encode([
            'type' => 'service_account',
            'project_id' => 'test-project',
            'private_key_id' => 'abc123',
            // Not PEM-shaped on purpose — a "BEGIN/END PRIVATE KEY" placeholder trips secret scanners; setAuthConfig() never validates the key's structure anyway.
            'private_key' => 'not-a-real-key-value',
            'client_email' => 'kanvas-sheets-agent@test-project.iam.gserviceaccount.com',
            'client_id' => '123456789',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]) ?: '';
    }

    public function test_builds_a_client_when_the_config_round_trips_as_an_already_decoded_array(): void
    {
        // Kanvas's custom-fields store decodes a stored JSON string back into an array on get() —
        // this is the shape that actually comes back in production, and once broke this with an
        // "Array to string conversion" error from a blind (string) cast.
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value, json_decode($this->fakeServiceAccountJson(), true));

        $service = Client::getInstance($app);

        $this->assertNotNull($service->spreadsheets_values);
    }

    public function test_builds_a_client_when_the_config_is_still_a_raw_json_string(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value, $this->fakeServiceAccountJson());

        $service = Client::getInstance($app);

        $this->assertNotNull($service->spreadsheets_values);
    }

    public function test_impersonates_the_configured_user_via_domain_wide_delegation(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value, $this->fakeServiceAccountJson());
        $app->set(ConfigurationEnum::IMPERSONATE_USER->value, 'apex@nzxt.com');

        $service = Client::getInstance($app);

        $this->assertSame('apex@nzxt.com', $service->getClient()->getConfig('subject'));
    }

    public function test_does_not_impersonate_anyone_when_the_key_is_left_unset(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value, $this->fakeServiceAccountJson());
        $app->set(ConfigurationEnum::IMPERSONATE_USER->value, '');

        $service = Client::getInstance($app);

        $this->assertNull($service->getClient()->getConfig('subject'));
    }

    public function test_throws_a_clear_error_when_nothing_is_configured(): void
    {
        $app = app(Apps::class);
        // Explicitly set then clear within the same test — proves the "nothing configured" path
        // rather than relying on ordering to find the key still unset from a fresh app.
        $app->set(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value, $this->fakeServiceAccountJson());
        $app->set(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value, '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        Client::getInstance($app);
    }
}
