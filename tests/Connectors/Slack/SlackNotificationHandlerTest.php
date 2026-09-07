<?php

declare(strict_types=1);

namespace Tests\Connectors\Slack;

use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Enums\NotificationConfigurationEnum;
use Kanvas\Connectors\Slack\Handlers\SlackNotificationHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class SlackNotificationHandlerTest extends TestCase
{
    private const string WEBHOOK_URL = 'https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXX';

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

    public function testSetupThrowsWhenNeitherCredentialIsProvided(): void
    {
        $handler = new SlackNotificationHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            []
        );

        $this->expectException(ValidationException::class);

        $handler->setup();
    }

    public function testSetupStoresAValidatedWebhookUrl(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('ok')]);

        $handler = new SlackNotificationHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            ['webhook_url' => self::WEBHOOK_URL, 'default_channel' => '#alerts']
        );

        $this->assertTrue($handler->setup());
        $this->assertSame(self::WEBHOOK_URL, $this->currentCompany->get(NotificationConfigurationEnum::WEBHOOK_URL->value));
        $this->assertSame('#alerts', $this->currentCompany->get(NotificationConfigurationEnum::DEFAULT_CHANNEL->value));
    }

    public function testSetupStoresAValidatedBotToken(): void
    {
        Http::fake([
            'slack.com/api/auth.test' => Http::response(['ok' => true, 'user_id' => 'UBOT1', 'team_id' => 'T1', 'team' => 'Acme']),
        ]);

        $handler = new SlackNotificationHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            ['bot_token' => 'xoxb-real-token']
        );

        $this->assertTrue($handler->setup());
        $this->assertSame('xoxb-real-token', $this->currentCompany->get(NotificationConfigurationEnum::BOT_TOKEN->value));
    }

    public function testSetupThrowsWhenSlackRejectsTheWebhook(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('invalid_payload', 400)]);

        $handler = new SlackNotificationHandler(
            $this->currentApp,
            $this->currentCompany,
            $this->currentRegion,
            ['webhook_url' => self::WEBHOOK_URL]
        );

        $this->expectException(ValidationException::class);

        $handler->setup();
    }
}
