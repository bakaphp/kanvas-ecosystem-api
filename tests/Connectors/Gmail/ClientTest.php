<?php

declare(strict_types=1);

namespace Tests\Connectors\Gmail;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Gmail\Client;
use Kanvas\Connectors\Gmail\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * These config keys live on the app's custom-fields store, which persists outside the ambient
     * test transaction — DatabaseTransactions does NOT roll it back. Save/restore explicitly so
     * this test never clobbers a real refresh token configured for this shared app.
     */
    private mixed $originalClientId = null;
    private mixed $originalClientSecret = null;
    private mixed $originalRefreshToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $this->originalClientId = $app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->originalClientSecret = $app->get(ConfigurationEnum::CLIENT_SECRET->value);
        $this->originalRefreshToken = $app->get(ConfigurationEnum::REFRESH_TOKEN->value);
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CLIENT_ID->value, $this->originalClientId);
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, $this->originalClientSecret);
        $app->set(ConfigurationEnum::REFRESH_TOKEN->value, $this->originalRefreshToken);

        parent::tearDown();
    }

    /**
     * No "happy path" test here on purpose — unlike GoogleSheets' setAuthConfig (purely local),
     * fetchAccessTokenWithRefreshToken() makes a real HTTP call to Google's OAuth token endpoint.
     * That path is covered by the live verification against a real refresh token instead.
     */
    public function test_throws_when_client_id_is_missing(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CLIENT_ID->value, '');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, 'secret');
        $app->set(ConfigurationEnum::REFRESH_TOKEN->value, 'refresh-token');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        Client::getInstance($app);
    }

    public function test_throws_when_client_secret_is_missing(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CLIENT_ID->value, 'client-id');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, '');
        $app->set(ConfigurationEnum::REFRESH_TOKEN->value, 'refresh-token');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        Client::getInstance($app);
    }

    public function test_throws_when_refresh_token_is_missing(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CLIENT_ID->value, 'client-id');
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, 'secret');
        $app->set(ConfigurationEnum::REFRESH_TOKEN->value, '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        Client::getInstance($app);
    }
}
