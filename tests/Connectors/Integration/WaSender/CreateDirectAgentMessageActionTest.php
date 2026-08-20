<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Enums\DirectConfigEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConversationModeEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Jobs\ProcessGroupBurstJob;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * PR5: a 1:1 with assistant treatment files against the Channel and never opens a Lead — the whole
 * connection via `direct_conversation_mode`, or specific counterparties via
 * `assistant_contact_jids` while everyone else keeps the historical lead flow.
 */
final class CreateDirectAgentMessageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private const string OWNER_PHONE = '18095550001';
    private const string CUSTOMER_PHONE = '18095550002';

    /**
     * The delivery dedupe is a 10-minute cache token keyed on the WhatsApp message id, so a shared
     * cache store would make a re-run of this file look like a redelivery.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);

        // Ingest arms the burst close; the debounce itself is ProcessGroupBurstJobTest's subject.
        Queue::fake();
    }

    public function testAssistantModeDmFilesAgainstAChannelAndNeverCreatesALead(): void
    {
        $this->configureReceiver([
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->value => DirectConversationModeEnum::ASSISTANT->value,
        ]);
        $leadsBefore = Lead::query()->count();

        $result = $this->ingest($this->directText(Str::uuid()->toString()));
        $filed = $result['result']['messages'][0];

        $this->assertSame('assistant', $filed['mode']);
        $this->assertSame('direct', $filed['conversation_type']);
        $this->assertNotNull($filed['people_id']);

        $message = Message::findOrFail($filed['message_id']);
        $channel = Channel::findOrFail($filed['channel_id']);

        $this->assertInstanceOf(Channel::class, $message->entity());
        $this->assertSame($channel->getId(), $message->entity()->getId());
        $this->assertSame('hey can you summarize my day', (string) $message->message['content']);
        $this->assertSame($leadsBefore, Lead::query()->count(), 'An assistant DM must never produce a Lead');
    }

    public function testAllowListedContactDivertsWhileOthersStillBecomeLeads(): void
    {
        $this->configureReceiver([
            DirectConfigEnum::ASSISTANT_CONTACT_JIDS->value => [self::OWNER_PHONE . '@s.whatsapp.net'],
        ]);
        $leadsBefore = Lead::query()->count();

        $owner = $this->ingest($this->directText(Str::uuid()->toString()));

        $this->assertSame('assistant', $owner['result']['messages'][0]['mode']);
        $this->assertSame($leadsBefore, Lead::query()->count());

        $customer = $this->ingest($this->directText(Str::uuid()->toString(), self::CUSTOMER_PHONE));

        $this->assertArrayNotHasKey('mode', $customer['result']['messages'][0]);
        $this->assertSame($leadsBefore + 1, Lead::query()->count(), 'An unlisted counterparty keeps the lead flow');
    }

    /**
     * Under lid addressing an operator may only ever see the lid form, so allow-listing by lid has
     * to match the same conversation a phone-form entry would.
     */
    public function testAllowListMatchesTheLidFormToo(): void
    {
        $this->configureReceiver([
            DirectConfigEnum::ASSISTANT_CONTACT_JIDS->value => ['168968509780173@lid'],
        ]);

        $result = $this->ingest([
            'key' => [
                'id' => Str::uuid()->toString(),
                'fromMe' => false,
                'remoteJid' => '168968509780173@lid',
                'remoteJidAlt' => '18096573168@s.whatsapp.net',
            ],
            'pushName' => 'Rafael Zapata',
            'remoteJid' => '168968509780173@lid',
            'message' => ['extendedTextMessage' => ['text' => 'resumeme el dia']],
        ]);

        $this->assertSame('assistant', $result['result']['messages'][0]['mode']);
    }

    public function testDefaultConfigKeepsEveryDmOnTheLeadPath(): void
    {
        $this->configureReceiver([]);
        $leadsBefore = Lead::query()->count();

        $result = $this->ingest($this->directText(Str::uuid()->toString()));

        $this->assertArrayNotHasKey('mode', $result['result']['messages'][0]);
        $this->assertSame($leadsBefore + 1, Lead::query()->count());
    }

    /**
     * A 1:1 is addressed by definition, so the burst uses the short mention window — text plus a
     * photo a few seconds later is still one agent turn.
     */
    public function testTextAndLaterPhotoCollapseIntoOneBurst(): void
    {
        $this->configureReceiver([
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->value => DirectConversationModeEnum::ASSISTANT->value,
        ]);

        $text = $this->ingestAt('10:15:00', $this->directText(Str::uuid()->toString()));
        $photo = $this->ingestAt('10:15:05', $this->directImage(Str::uuid()->toString()));

        $this->assertSame(
            $text['result']['messages'][0]['message_id'],
            $photo['result']['messages'][0]['parent_id']
        );
    }

    /**
     * `messages.upsert` echoes outgoing messages back; arming a burst on one would have the agent
     * answer itself, forever.
     */
    public function testOwnOutboundMessageIsFiledButNeverArmsABurst(): void
    {
        $this->configureReceiver([
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->value => DirectConversationModeEnum::ASSISTANT->value,
        ]);

        $result = $this->ingest($this->directText(Str::uuid()->toString(), self::OWNER_PHONE, fromMe: true));
        $filed = $result['result']['messages'][0];

        $this->assertTrue($filed['is_from_me']);
        $this->assertNull($filed['people_id'], 'Our own message resolves no counterparty People');
        Queue::assertNotPushed(ProcessGroupBurstJob::class);
    }

    private function configureReceiver(array $configuration): void
    {
        $receiver = $this->receiver();
        $receiver->configuration = [...$receiver->configuration, ...$configuration];
        $receiver->saveOrFail();
    }

    private function ingestAt(string $time, array $messageData): array
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 ' . $time));

        try {
            return $this->ingest($messageData);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function ingest(array $messageData): array
    {
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $this->receiver()->getId(),
            'url' => $this->receiver()->getUrl(),
            'headers' => [],
            'payload' => [
                'event' => WebhookEventEnum::MESSAGES_UPSERT->value,
                'data' => ['messages' => $messageData],
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

    private function directText(
        string $messageId,
        string $phone = self::OWNER_PHONE,
        bool $fromMe = false
    ): array {
        return [
            ...$this->directEnvelope($messageId, $phone, $fromMe),
            'message' => [
                'messageContextInfo' => ['messageSecret' => 'x'],
                'extendedTextMessage' => ['text' => 'hey can you summarize my day'],
            ],
        ];
    }

    private function directImage(string $messageId, string $phone = self::OWNER_PHONE): array
    {
        return [
            ...$this->directEnvelope($messageId, $phone, false),
            'message' => [
                'imageMessage' => [
                    'url' => 'https://mmg.whatsapp.net/o1/v/t24/f2/m000/EXAMPLE',
                    'mimetype' => 'image/jpeg',
                    'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHk1',
                ],
                'messageContextInfo' => ['messageSecret' => 'y'],
            ],
        ];
    }

    private function directEnvelope(string $messageId, string $phone, bool $fromMe): array
    {
        return [
            'key' => [
                'id' => $messageId,
                'fromMe' => $fromMe,
                'remoteJid' => $phone . '@s.whatsapp.net',
            ],
            'pushName' => $fromMe ? null : 'Max Owner',
            'remoteJid' => $phone . '@s.whatsapp.net',
        ];
    }
}
