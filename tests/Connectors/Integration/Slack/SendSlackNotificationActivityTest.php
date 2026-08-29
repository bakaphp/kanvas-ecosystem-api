<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Slack\Activities\SendSlackNotificationActivity;
use Kanvas\Connectors\Slack\Enums\NotificationConfigurationEnum;
use Kanvas\Connectors\Slack\Handlers\SlackNotificationHandler;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class SendSlackNotificationActivityTest extends TestCase
{
    use HasIntegrationCompany;

    private const string WEBHOOK_URL = 'https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXX';

    protected function setUp(): void
    {
        parent::setUp();

        // The cached test company/user is shared across test classes in this suite; clear any
        // credential a previous test may have left configured.
        foreach (NotificationConfigurationEnum::cases() as $case) {
            $this->company()->del($case->value);
        }
    }

    public function testSendsThroughTheConfiguredWebhook(): void
    {
        $this->registerIntegration();
        $this->company()->set(NotificationConfigurationEnum::WEBHOOK_URL->value, self::WEBHOOK_URL);

        Http::fake([self::WEBHOOK_URL => Http::response('ok')]);

        $result = $this->activity()->execute(
            $this->makeMessage(),
            app(Apps::class),
            ['text' => 'New order received']
        );

        $this->assertSame('webhook', $result['via']);
        Http::assertSent(fn (Request $request): bool => $request['text'] === 'New order received');
    }

    public function testFailsTheWorkflowWhenTextParamIsMissing(): void
    {
        $result = $this->activity()->execute($this->makeMessage(), app(Apps::class), []);

        $this->assertStringContainsString('text', $result['message']);
    }

    public function testFailsTheWorkflowWhenSlackIsNotConfigured(): void
    {
        $this->registerIntegration();

        $result = $this->activity()->execute(
            $this->makeMessage(),
            app(Apps::class),
            ['text' => 'New order received']
        );

        $this->assertStringContainsString('not configured', $result['message']);
    }

    private function activity(): SendSlackNotificationActivity
    {
        return new SendSlackNotificationActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
    }

    private function registerIntegration(): void
    {
        $user = auth()->user();

        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::SLACK,
            SlackNotificationHandler::class,
            $user->getCurrentCompany(),
            $user
        );
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    private function makeMessage(): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['message' => ['content' => 'A new order came in.']]);
    }
}
