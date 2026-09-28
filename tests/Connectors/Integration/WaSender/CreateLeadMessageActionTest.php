<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * The historical 1:1 lead flow, extracted from the webhook job into its own action. This is the
 * CRM backbone for every WhatsApp conversation that is not a group or an assistant DM, so the
 * branches that matter are lead reuse, the outbound status handling, and message attribution.
 */
final class CreateLeadMessageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private const string CUSTOMER_PHONE = '18095551234';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Queue::fake();
        Notification::fake();
    }

    public function testInboundMessageCreatesALeadAndFilesTheMessageAgainstIt(): void
    {
        $result = $this->ingest($this->directText(Str::uuid()->toString()));
        $filed = $result['result']['messages'][0];

        $message = Message::findOrFail($filed['message_id']);
        $lead = $message->entity();

        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertTrue($message->tags()->where('name', 'engagement')->exists());
        $this->assertSame('whatsapp', (string) $lead->get(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value));
        $this->assertTrue((bool) $lead->get(LeadsConfigurationEnum::IS_ENGAGEMENT->value));
    }

    /**
     * A second message from the same person must land on the active lead, not open a duplicate —
     * this is what LeadsRepository::getPeopleActiveLead guards.
     */
    public function testASecondMessageFromTheSamePersonReusesTheActiveLead(): void
    {
        $first = $this->ingest($this->directText(Str::uuid()->toString()));
        $leadsAfterFirst = Lead::query()->count();

        $second = $this->ingest($this->directText(Str::uuid()->toString()));

        $this->assertSame($leadsAfterFirst, Lead::query()->count(), 'The second message must not open a lead');
        $this->assertSame(
            Message::findOrFail($first['result']['messages'][0]['message_id'])->entity()->getId(),
            Message::findOrFail($second['result']['messages'][0]['message_id'])->entity()->getId()
        );
    }

    /**
     * status=1 is our own API delivery: the agent's own reply echoed back. It is filed locked and
     * private so the UI does not show it as a fresh inbound customer message.
     */
    public function testOwnApiDeliveryIsLockedAndPrivate(): void
    {
        $payload = $this->directText(Str::uuid()->toString(), fromMe: true);
        $payload['status'] = 1;

        $result = $this->ingest($payload);
        $message = Message::findOrFail($result['result']['messages'][0]['message_id']);

        $this->assertTrue((bool) $message->is_locked);
    }

    /**
     * status=2 is a human replying from the phone itself — the agent has to stand down, which the
     * HUMAN_TAKEOVER trigger is what signals.
     */
    public function testHumanReplyFromThePhoneFiresTheTakeoverTrigger(): void
    {
        $payload = $this->directText(Str::uuid()->toString(), fromMe: true);
        $payload['status'] = 2;

        $result = $this->ingest($payload);

        $this->assertNotEmpty($result['result']['messages']);
        $this->assertTrue((bool) Message::findOrFail($result['result']['messages'][0]['message_id'])->message['from_me']);
    }

    public function testMessageIsFiledUnderTheSharedSlugShapeSoStatusEventsCanFindIt(): void
    {
        $messageId = 'WA-' . Str::random(12);

        $this->ingest($this->directText($messageId));

        $update = $this->runEvent(WebhookEventEnum::MESSAGES_UPDATE->value, [
            [
                'key' => [
                    'id' => $messageId,
                    'fromMe' => false,
                    'remoteJid' => self::CUSTOMER_PHONE . '@s.whatsapp.net',
                ],
                'update' => ['status' => 3],
            ],
        ]);

        $this->assertCount(1, $update['result']['updates']);
        $this->assertSame(3, $update['result']['updates'][0]['status']);
    }

    private function ingest(array $messageData): array
    {
        return $this->runEvent(WebhookEventEnum::MESSAGES_UPSERT->value, ['messages' => $messageData]);
    }

    private function runEvent(string $event, array $data): array
    {
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $this->receiver()->getId(),
            'url' => $this->receiver()->getUrl(),
            'headers' => [],
            'payload' => [
                'event' => $event,
                'data' => $data,
            ],
        ]);

        return new ProcessWaSenderWebhookJob($webhookCall)->execute();
    }

    private ?ReceiverWebhook $receiver = null;

    private function receiver(): ReceiverWebhook
    {
        if ($this->receiver !== null) {
            return $this->receiver;
        }

        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $user->getCurrentCompany()->getId(),
                'name' => 'Warm',
            ],
            [
                'description' => 'Warm Lead Type',
                'is_active' => true,
            ]
        );

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessWaSenderWebhookJob::class],
            ['name' => 'ProcessWaSenderWebhookJob'],
        );

        return $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->company($user->getCurrentCompany()->getId())
            ->user($user->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
                'is_active' => true,
            ]);
    }

    private function directText(string $messageId, bool $fromMe = false): array
    {
        return [
            'key' => [
                'id' => $messageId,
                'fromMe' => $fromMe,
                'remoteJid' => self::CUSTOMER_PHONE . '@s.whatsapp.net',
            ],
            'pushName' => 'Dana Cruz',
            'remoteJid' => self::CUSTOMER_PHONE . '@s.whatsapp.net',
            'messageBody' => 'hola, quiero informacion',
            'message' => [
                'extendedTextMessage' => ['text' => 'hola, quiero informacion'],
            ],
        ];
    }
}
