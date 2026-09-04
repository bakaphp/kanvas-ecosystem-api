<?php

declare(strict_types=1);

namespace Tests\Connectors\Trello;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Trello\Enums\ConfigurationEnum;
use Kanvas\Connectors\Trello\Handlers\TrelloHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class TrelloHandlerTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;
    private Regions $currentRegion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->currentRegion = Regions::getDefault($this->currentCompany, $this->currentApp);
    }

    public function testSetupThrowsWhenCredentialsAreMissing(): void
    {
        $handler = new TrelloHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            ['api_key' => '', 'api_token' => '']
        );

        $this->expectException(ValidationException::class);

        $handler->setup();
    }

    public function testSetupStoresCredentialsWhenTrelloAcceptsThem(): void
    {
        Http::fake([
            'api.trello.com/1/members/me*' => Http::response(['id' => 'me1', 'username' => 'kanvas']),
        ]);

        $handler = new TrelloHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            ['api_key' => 'real-key', 'api_token' => 'real-token']
        );

        $this->assertTrue($handler->setup());
        $this->assertSame('real-key', $this->currentCompany->get(ConfigurationEnum::API_KEY->value));
        $this->assertSame('real-token', $this->currentCompany->get(ConfigurationEnum::API_TOKEN->value));
    }

    public function testSetupThrowsWhenTrelloRejectsTheCredentials(): void
    {
        Http::fake([
            'api.trello.com/1/members/me*' => Http::response(['message' => 'invalid token'], 401),
        ]);

        $handler = new TrelloHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            ['api_key' => 'bad-key', 'api_token' => 'bad-token']
        );

        $this->expectException(ValidationException::class);

        $handler->setup();
    }
}
