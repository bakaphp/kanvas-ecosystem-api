<?php

declare(strict_types=1);

namespace Tests\Connectors\Jira;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Jira\Enums\ConfigurationEnum;
use Kanvas\Connectors\Jira\Handlers\JiraHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class JiraHandlerTest extends TestCase
{
    private const string INSTANCE_URL = 'https://kanvas.atlassian.net';

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
        $handler = new JiraHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            ['instance_url' => '', 'email' => '', 'api_token' => '']
        );

        $this->expectException(ValidationException::class);

        $handler->setup();
    }

    public function testSetupStoresConfigurationWhenJiraAcceptsIt(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/myself' => Http::response(['accountId' => 'abc123']),
        ]);

        $handler = new JiraHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            [
                'instance_url' => self::INSTANCE_URL . '/',
                'email' => 'agent@kanvas.test',
                'api_token' => 'test-api-token',
                'default_project_key' => 'OPS',
                'default_issue_type' => 'Bug',
            ]
        );

        $this->assertTrue($handler->setup());
        $this->assertSame(self::INSTANCE_URL, $this->currentCompany->get(ConfigurationEnum::INSTANCE_URL->value));
        $this->assertSame('agent@kanvas.test', $this->currentCompany->get(ConfigurationEnum::EMAIL->value));
        $this->assertSame('test-api-token', $this->currentCompany->get(ConfigurationEnum::API_TOKEN->value));
        $this->assertSame('OPS', $this->currentCompany->get(ConfigurationEnum::DEFAULT_PROJECT_KEY->value));
        $this->assertSame('Bug', $this->currentCompany->get(ConfigurationEnum::DEFAULT_ISSUE_TYPE->value));
    }

    public function testSetupThrowsWhenJiraRejectsTheCredentials(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/myself' => Http::response('', 401),
        ]);

        $handler = new JiraHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            [
                'instance_url' => self::INSTANCE_URL,
                'email' => 'agent@kanvas.test',
                'api_token' => 'bad-token',
            ]
        );

        $this->expectException(ValidationException::class);

        $handler->setup();
    }
}
