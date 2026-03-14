<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Calendly;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Calendly\Client;
use Kanvas\Connectors\Calendly\Enums\ConfigurationEnum;
use Kanvas\Connectors\Calendly\Handlers\CalendlyHandler;
use Kanvas\Connectors\Calendly\Jobs\ProcessCalendlyWebhookJob;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver;
use Kanvas\Guild\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class CalendlyTest extends TestCase
{
    use HasIntegrationCompany;

    private function skipIfNoApiKey(): void
    {
        if (empty(getenv('TEST_CALENDLY_API_KEY'))) {
            $this->markTestSkipped('TEST_CALENDLY_API_KEY not configured.');
        }

        try {
            $company = $this->getConfiguredCompany();
            $client = new Client($company);
            $client->getCurrentUser();
        } catch (Exception $e) {
            $this->markTestSkipped('Calendly API key does not have required scopes: ' . $e->getMessage());
        }
    }

    private function getConfiguredCompany(): Companies
    {
        $company = Companies::first();
        $company->set(ConfigurationEnum::CALENDLY_API_TOKEN->value, getenv('TEST_CALENDLY_API_KEY'));

        return $company;
    }

    public function testCalendlyClientGetCurrentUser(): void
    {
        $this->skipIfNoApiKey();

        $company = $this->getConfiguredCompany();
        $client = new Client($company);

        $response = $client->getCurrentUser();

        $this->assertArrayHasKey('resource', $response);
        $this->assertArrayHasKey('uri', $response['resource']);
        $this->assertArrayHasKey('current_organization', $response['resource']);
    }

    public function testCalendlyHandlerSetup(): void
    {
        $this->skipIfNoApiKey();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = Companies::first();

        $this->setIntegration(
            $app,
            IntegrationsEnum::CALENDLY,
            CalendlyHandler::class,
            $company,
            $user
        );

        $region = Regions::getDefault($company, $app);

        $handler = new CalendlyHandler(
            $app,
            $company,
            $region,
            [
                'api_token' => getenv('TEST_CALENDLY_API_KEY'),
                'callback_url' => 'https://example.com/webhook/calendly-test',
                'events' => ['invitee.created'],
            ]
        );

        $result = $handler->setup();

        $this->assertTrue($result);
        $this->assertNotEmpty($company->get(ConfigurationEnum::CALENDLY_USER_URI->value));
        $this->assertNotEmpty($company->get(ConfigurationEnum::CALENDLY_ORGANIZATION_URI->value));

        $webhookUri = $company->get(ConfigurationEnum::CALENDLY_WEBHOOK_SUBSCRIPTION_URI->value);
        $this->assertNotEmpty($webhookUri);

        $client = new Client($company);
        $client->deleteWebhookSubscription($webhookUri);
    }

    public function testProcessCalendlyWebhookCreatesLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = Companies::first();

        $companySetup = new Setup($app, $user, $company);
        $companySetup->run();

        new CreateLeadReceiverAction(
            new LeadReceiver(
                app: $app,
                branch: $company->defaultBranch,
                user: $user,
                agent: $user,
                name: 'Calendly Test Receiver',
                source: 'calendly',
                isDefault: true
            )
        )->execute();

        $receiverWebhook = ReceiverWebhook::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'action_id' => 0,
            'configuration' => [
                'pipeline_stage_id' => 0,
                'source_id' => 0,
            ],
        ]);

        $webhookCall = new ReceiverWebhookCall();
        $webhookCall->receiver_webhooks_id = $receiverWebhook->getId();
        $webhookCall->url = 'https://example.com/webhook/calendly';
        $webhookCall->payload = [
            'event' => 'invitee.created',
            'payload' => [
                'invitee' => [
                    'uri' => 'https://api.calendly.com/scheduled_events/abc123/invitees/def456',
                    'name' => 'John Doe',
                    'email' => 'john.doe@example.com',
                    'text_reminder_number' => '+18095551234',
                    'questions_and_answers' => [],
                ],
                'event' => [
                    'uri' => 'https://api.calendly.com/scheduled_events/abc123',
                    'name' => 'Discovery Call',
                    'event_type' => 'https://api.calendly.com/event_types/xyz789',
                ],
            ],
        ];
        $webhookCall->saveOrFail();

        $job = new ProcessCalendlyWebhookJob($webhookCall);
        $result = $job->execute();

        $this->assertArrayHasKey('lead_id', $result);
        $this->assertArrayHasKey('event', $result);
        $this->assertEquals('invitee.created', $result['event']);
        $this->assertEquals('john.doe@example.com', $result['invitee_email']);
    }

    public function testProcessCalendlyWebhookSkipsFilteredEvents(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $receiverWebhook = ReceiverWebhook::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => Companies::first()->getId(),
            'users_id' => $user->getId(),
            'action_id' => 0,
            'configuration' => [
                'event_types' => ['invitee.created'],
            ],
        ]);

        $webhookCall = new ReceiverWebhookCall();
        $webhookCall->receiver_webhooks_id = $receiverWebhook->getId();
        $webhookCall->url = 'https://example.com/webhook/calendly';
        $webhookCall->payload = [
            'event' => 'invitee.canceled',
            'payload' => [
                'invitee' => [
                    'name' => 'Jane Doe',
                    'email' => 'jane@example.com',
                ],
                'event' => [
                    'name' => 'Discovery Call',
                ],
            ],
        ];
        $webhookCall->saveOrFail();

        $job = new ProcessCalendlyWebhookJob($webhookCall);
        $result = $job->execute();

        $this->assertTrue($result['skipped']);
        $this->assertStringContainsString('invitee.canceled', $result['message']);
    }
}
