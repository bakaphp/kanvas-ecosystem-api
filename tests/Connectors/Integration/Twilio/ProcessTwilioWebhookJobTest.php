<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class ProcessTwilioWebhookJobTest extends TestCase
{
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, 'test_sid');
        $app->set(ConfigurationEnum::TWILIO_AUTH_TOKEN->value, 'test_token');

        LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Warm',
            ],
            [
                'description' => 'Warm Lead Type',
                'is_active' => true,
                'uuid' => Str::uuid(),
            ]
        );

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessTwilioWebhookJob::class],
            ['name' => 'ProcessTwilioWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testProcessIncomingSmsCreatesLeadAndMessage(): void
    {
        $payload = $this->buildTwilioPayload();

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('message_id', $result[0]);
        $this->assertArrayHasKey('channel_id', $result[0]);
        $this->assertFalse($result[0]['is_from_me']);
    }

    public function testProcessIncomingSmsWithMediaAttachesToLead(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response('fake-image-content', 200),
        ]);

        $payload = $this->buildTwilioPayload([
            'NumMedia' => '1',
            'MediaUrl0' => 'https://api.twilio.com/2010-04-01/Accounts/test/Messages/SM123/Media/ME123',
            'MediaContentType0' => 'image/jpeg',
        ]);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $messageId = $result[0]['message_id'];
        $chatJid = $result[0]['chat_jid'];

        $lead = Lead::fromApp(app(Apps::class))
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->where('title', 'like', '%Twilio Opp%')
            ->latest('id')
            ->first();

        $this->assertNotNull($lead, 'Lead should have been created');

        $leadFiles = $lead->getFiles();
        $this->assertNotEmpty($leadFiles, 'Lead should have files attached for the digital jacket');
    }

    private function buildTwilioPayload(array $overrides = []): array
    {
        $phone = '+1' . fake()->numerify('##########');

        return array_merge([
            'SmsMessageSid' => 'SM' . Str::random(32),
            'NumMedia' => '0',
            'SmsSid' => 'SM' . Str::random(32),
            'SmsStatus' => 'received',
            'Body' => 'Hello, I am interested in your services',
            'To' => '+18001234567',
            'From' => $phone,
            'AccountSid' => 'AC' . Str::random(32),
            'NumSegments' => '1',
            'ApiVersion' => '2010-04-01',
        ], $overrides);
    }

    private function dispatchWebhookJob(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        Queue::fake();

        $job = new ProcessTwilioWebhookJob($webhookRequest);

        return $job->handle();
    }
}
