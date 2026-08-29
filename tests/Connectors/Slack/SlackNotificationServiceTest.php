<?php

declare(strict_types=1);

namespace Tests\Connectors\Slack;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Enums\NotificationConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackNotificationService;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class SlackNotificationServiceTest extends TestCase
{
    private const string WEBHOOK_URL = 'https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXX';

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();

        // The cached test company/user is shared across test classes in this suite; clear any
        // credential a previous test may have left configured so "not configured yet" is actually
        // exercised regardless of run order.
        foreach (NotificationConfigurationEnum::cases() as $case) {
            $this->currentCompany->del($case->value);
        }
    }

    public function testIsConfiguredIsFalseUntilACredentialIsStored(): void
    {
        $service = new SlackNotificationService($this->currentApp, $this->currentCompany);

        $this->assertFalse($service->isConfigured());
    }

    public function testSendThrowsWhenNothingIsConfigured(): void
    {
        $service = new SlackNotificationService($this->currentApp, $this->currentCompany);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not configured');

        $service->send('hello');
    }

    public function testSendPrefersTheWebhookWhenBothCredentialsAreConfigured(): void
    {
        $this->currentCompany->set(NotificationConfigurationEnum::WEBHOOK_URL->value, self::WEBHOOK_URL);
        $this->currentCompany->set(NotificationConfigurationEnum::BOT_TOKEN->value, 'xoxb-should-not-be-used');

        Http::fake([self::WEBHOOK_URL => Http::response('ok')]);

        $service = new SlackNotificationService($this->currentApp, $this->currentCompany);
        $result = $service->send('Deploy finished');

        $this->assertSame('webhook', $result['via']);
        Http::assertSent(
            fn (Request $request): bool => $request->url() === self::WEBHOOK_URL
                && $request['text'] === 'Deploy finished'
        );
    }

    public function testSendViaWebhookThrowsWhenSlackRejectsIt(): void
    {
        $this->currentCompany->set(NotificationConfigurationEnum::WEBHOOK_URL->value, self::WEBHOOK_URL);

        Http::fake([self::WEBHOOK_URL => Http::response('invalid_payload', 400)]);

        $service = new SlackNotificationService($this->currentApp, $this->currentCompany);

        $this->expectException(ValidationException::class);

        $service->send('Deploy finished');
    }

    public function testSendViaBotTokenUsesTheExplicitChannelOverTheDefault(): void
    {
        $this->currentCompany->set(NotificationConfigurationEnum::BOT_TOKEN->value, 'xoxb-real-token');
        $this->currentCompany->set(NotificationConfigurationEnum::DEFAULT_CHANNEL->value, '#general');

        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '123.456']),
        ]);

        $service = new SlackNotificationService($this->currentApp, $this->currentCompany);
        $result = $service->send('Deploy finished', '#alerts');

        $this->assertSame('#alerts', $result['channel']);
        $this->assertSame('123.456', $result['ts']);
        $this->assertSame('bot_token', $result['via']);
        Http::assertSent(fn (Request $request): bool => $request['channel'] === '#alerts');
    }

    public function testSendViaBotTokenFallsBackToTheDefaultChannel(): void
    {
        $this->currentCompany->set(NotificationConfigurationEnum::BOT_TOKEN->value, 'xoxb-real-token');
        $this->currentCompany->set(NotificationConfigurationEnum::DEFAULT_CHANNEL->value, '#general');

        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '123.456']),
        ]);

        $service = new SlackNotificationService($this->currentApp, $this->currentCompany);
        $result = $service->send('Deploy finished');

        $this->assertSame('#general', $result['channel']);
        Http::assertSent(fn (Request $request): bool => $request['channel'] === '#general');
    }

    public function testSendViaBotTokenThrowsWhenNoChannelIsAvailable(): void
    {
        $this->currentCompany->set(NotificationConfigurationEnum::BOT_TOKEN->value, 'xoxb-real-token');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('channel is required');

        new SlackNotificationService($this->currentApp, $this->currentCompany)->send('Deploy finished');
    }

    public function testValidateWebhookReturnsTrueWhenSlackAccepts(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('ok')]);

        $this->assertTrue(SlackNotificationService::validateWebhook(self::WEBHOOK_URL));
    }

    public function testValidateWebhookThrowsWhenSlackRejects(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('invalid_payload', 400)]);

        $this->expectException(ValidationException::class);

        SlackNotificationService::validateWebhook(self::WEBHOOK_URL);
    }

    public function testValidateBotTokenReturnsTrueWhenSlackAccepts(): void
    {
        Http::fake([
            'slack.com/api/auth.test' => Http::response(['ok' => true, 'user_id' => 'UBOT1', 'team_id' => 'T1', 'team' => 'Acme']),
        ]);

        $this->assertTrue(SlackNotificationService::validateBotToken('xoxb-real-token'));
    }

    public function testValidateBotTokenThrowsWhenSlackRejects(): void
    {
        Http::fake([
            'slack.com/api/auth.test' => Http::response(['ok' => false, 'error' => 'invalid_auth']),
        ]);

        $this->expectException(ValidationException::class);

        SlackNotificationService::validateBotToken('xoxb-bad-token');
    }
}
