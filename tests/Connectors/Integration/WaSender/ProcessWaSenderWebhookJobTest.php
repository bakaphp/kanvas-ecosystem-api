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
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * PR1 behaviour: every inbound message routes through InboundMessage, unroutable and duplicate
 * deliveries are skipped silently, and lead-less channels persist. Payloads are trimmed copies of
 * the production capture behind Sentry KANVAS-ECOSYSTEM-67S.
 */
final class ProcessWaSenderWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    /**
     * The delivery dedupe is a 10-minute cache token keyed on the WhatsApp message id, so a shared
     * cache store would make a re-run of this file look like a redelivery.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Queue::fake();
        Notification::fake();
    }

    private const string GROUP_JID = '15550001111-1700000000@g.us';

    /**
     * The regression: this exact payload used to resolve to a null chat JID, hit report(), and
     * fire the Sentry issue on every group message. It now resolves, and lands on the allow-list
     * gate instead — a group nobody opted into is dropped quietly.
     */
    public function testGroupMessageOutsideTheAllowListIsSkippedWithoutReporting(): void
    {
        $result = $this->runEvent(
            WebhookEventEnum::MESSAGES_UPSERT->value,
            ['messages' => $this->groupTextMessage(Str::uuid()->toString())]
        );

        $this->assertSame([], $result['result']['messages']);
        $this->assertCount(1, $result['result']['skipped']);
        $this->assertSame('group', $result['result']['skipped'][0]['conversation_type']);
        $this->assertSame(
            'group not allow-listed or message has no content',
            $result['result']['skipped'][0]['reason']
        );
    }

    public function testMessageWithoutARoutableConversationIsSkipped(): void
    {
        $result = $this->runEvent(
            WebhookEventEnum::MESSAGES_UPSERT->value,
            [
                'messages' => [
                    'key' => [
                        'id' => Str::uuid()->toString(),
                        'fromMe' => false,
                    ],
                    'message' => ['conversation' => 'orphan'],
                ],
            ]
        );

        $this->assertSame([], $result['result']['messages']);
        $this->assertSame('no routable conversation jid', $result['result']['skipped'][0]['reason']);
    }

    /**
     * A forwarded ad card: extendedTextMessage carrying only mediaKey + contextInfo.
     * Real capture: a forwarded ad card.
     */
    public function testMessageWithNoContentIsSkippedBeforeAnyLeadWork(): void
    {
        $messageId = Str::uuid()->toString();

        $result = $this->runEvent(
            WebhookEventEnum::MESSAGES_UPSERT->value,
            [
                'messages' => [
                    'key' => [
                        'id' => $messageId,
                        'fromMe' => false,
                        'remoteJid' => '15550003333@s.whatsapp.net',
                    ],
                    'message' => [
                        'messageContextInfo' => ['messageSecret' => 'x'],
                        'extendedTextMessage' => [
                            'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHk0',
                            'contextInfo' => ['isForwarded' => true],
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame([], $result['result']['messages']);
        $this->assertSame(
            [
                'message_id' => $messageId,
                'reason' => 'no content to file',
            ],
            $result['result']['skipped'][0]
        );
    }

    /**
     * WaSender documents no delivery or ordering semantics, so a retry is ours to absorb.
     */
    public function testDirectMessageRedeliveryIsCaughtByTheDedupeGate(): void
    {
        $messageId = 'wa-dedupe-' . Str::random(10);
        $payload = [
            'messages' => [
                'key' => [
                    'id' => $messageId,
                    'fromMe' => false,
                    'remoteJid' => '15550003333@s.whatsapp.net',
                ],
                'message' => [
                    'messageContextInfo' => ['messageSecret' => 'x'],
                    'extendedTextMessage' => ['contextInfo' => []],
                ],
            ],
        ];

        // First pass consumes the dedupe token, then falls out on empty content.
        $first = $this->runEvent(WebhookEventEnum::MESSAGES_UPSERT->value, $payload);
        $second = $this->runEvent(WebhookEventEnum::MESSAGES_UPSERT->value, $payload);

        $this->assertSame('no content to file', $first['result']['skipped'][0]['reason']);
        $this->assertSame('duplicate delivery', $second['result']['skipped'][0]['reason']);
    }

    /**
     * The live groups.upsert (capture 2026-08-19) nests the list under data.groups — and arrives
     * empty. Both shapes must be a clean no-op, not an iteration over the wrapper.
     */
    public function testEmptyGroupsUpsertIsANoOp(): void
    {
        $result = $this->runEvent(
            WebhookEventEnum::GROUPS_UPSERT->value,
            ['groups' => []]
        );

        $this->assertSame([], $result['result']['groups']);
    }

    /**
     * WaSender fans one message across four events. We ingest from messages.upsert only, and the
     * mirrors must read as an intentional skip rather than "Unknown event type".
     */
    public function testInboundMirrorEventsAreRecognisedAndIgnored(): void
    {
        foreach (WebhookEventEnum::duplicateMessageEvents() as $event) {
            $result = $this->runEvent($event->value, ['messages' => $this->groupTextMessage(Str::uuid()->toString())]);

            $this->assertFalse($result['result']['processed'], $event->value);
            $this->assertSame('Duplicate of messages.upsert, ignored by design', $result['result']['reason']);
            $this->assertSame($event->value, $result['result']['type']);
        }
    }

    /**
     * getOrCreateChannel only saved inside `if ($lead)`, so a group channel was rebuilt and thrown
     * away on every delivery — the capture shows `{"status": "created", "channel_id": null}` every
     * single time.
     */
    public function testLeadLessChannelIsPersistedWithAnId(): void
    {
        $result = $this->runEvent(
            WebhookEventEnum::CHATS_UPDATE->value,
            ['chats' => ['id' => self::GROUP_JID]]
        );

        $update = $result['result']['updates'][0];

        $this->assertNotNull($update['channel_id'], 'A lead-less channel must persist');

        $channel = Channel::find($update['channel_id']);

        $this->assertNotNull($channel);
        $this->assertSame('wa-group-' . Str::slug(self::GROUP_JID), $channel->slug);
    }

    public function testTheSameGroupResolvesToOneChannelAcrossDeliveries(): void
    {
        $payload = ['chats' => ['id' => self::GROUP_JID]];

        $first = $this->runEvent(WebhookEventEnum::CHATS_UPDATE->value, $payload);
        $second = $this->runEvent(WebhookEventEnum::CHATS_UPDATE->value, $payload);

        $this->assertSame(
            $first['result']['updates'][0]['channel_id'],
            $second['result']['updates'][0]['channel_id']
        );
    }

    /**
     * The status handlers used to build their slug from raw `key.remoteJid`. Under lid addressing
     * the message was filed under the phone form (`remoteJidAlt`), so the update matched nothing
     * and was silently dropped.
     */
    public function testStatusUpdateFindsALidAddressedMessage(): void
    {
        $messageId = 'WA-LID-' . Str::random(10);

        $this->runEvent(WebhookEventEnum::MESSAGES_UPSERT->value, ['messages' => $this->lidDirectMessage($messageId)]);

        $result = $this->runEvent(WebhookEventEnum::MESSAGES_UPDATE->value, [
            [
                'key' => $this->lidKey($messageId),
                'update' => ['status' => 4],
            ],
        ]);

        $this->assertCount(1, $result['result']['updates']);
        $this->assertSame(4, $result['result']['updates'][0]['status']);
    }

    public function testReactionReceiptAndDeleteAllResolveTheFiledMessage(): void
    {
        $messageId = 'WA-EVT-' . Str::random(10);

        $this->runEvent(WebhookEventEnum::MESSAGES_UPSERT->value, ['messages' => $this->lidDirectMessage($messageId)]);

        $reaction = $this->runEvent(WebhookEventEnum::MESSAGES_REACTION->value, [
            [
                'key' => $this->lidKey($messageId),
                'reaction' => ['text' => '👍'],
            ],
        ]);

        $receipt = $this->runEvent(WebhookEventEnum::MESSAGE_RECEIPT_UPDATE->value, [
            [
                'key' => $this->lidKey($messageId),
                'receipt' => ['status' => 'read', 't' => 1787141648],
            ],
        ]);

        $delete = $this->runEvent(WebhookEventEnum::MESSAGES_DELETE->value, [
            'keys' => [$this->lidKey($messageId)],
        ]);

        $this->assertSame('👍', $reaction['result']['reactions'][0]['reaction']);
        $this->assertSame('read', $receipt['result']['receipts'][0]['receipt_status']);
        $this->assertCount(1, $delete['result']['deleted']);
        $this->assertTrue(
            (bool) Message::withoutGlobalScopes()
                ->findOrFail($delete['result']['deleted'][0]['message_id'])
                ->is_deleted
        );
    }

    public function testChatUpdateRenamesAnExistingChannel(): void
    {
        $created = $this->runEvent(WebhookEventEnum::CHATS_UPDATE->value, ['chats' => ['id' => self::GROUP_JID]]);
        $channelId = $created['result']['updates'][0]['channel_id'];

        $this->runEvent(
            WebhookEventEnum::CHATS_UPDATE->value,
            ['chats' => ['id' => self::GROUP_JID, 'name' => 'Comité de Prensa']]
        );

        $this->assertSame('Comité de Prensa', Channel::findOrFail($channelId)->name);
    }

    public function testPopulatedGroupsUpsertCreatesTheChannelWithItsSubject(): void
    {
        $result = $this->runEvent(
            WebhookEventEnum::GROUPS_UPSERT->value,
            ['groups' => [['jid' => self::GROUP_JID, 'subject' => 'Prensa Nacional']]]
        );

        $this->assertCount(1, $result['result']['groups']);
        $this->assertSame('Prensa Nacional', Channel::findOrFail($result['result']['groups'][0]['channel_id'])->name);
    }

    public function testContactUpsertCreatesPeopleAndChannel(): void
    {
        $result = $this->runEvent(
            WebhookEventEnum::CONTACTS_UPSERT->value,
            [['jid' => '18095559876@s.whatsapp.net', 'name' => 'Nadia Fuentes']]
        );

        $contact = $result['result']['contacts'][0];

        $this->assertNotNull($contact['people_id']);
        $this->assertNotNull($contact['channel_id']);
        $this->assertSame('Nadia Fuentes', People::findOrFail($contact['people_id'])->getName());
    }

    /**
     * @return array<string, mixed>
     */
    private function lidKey(string $messageId): array
    {
        return [
            'id' => $messageId,
            'fromMe' => false,
            'remoteJid' => '168968509780173@lid',
            'remoteJidAlt' => '18096573168@s.whatsapp.net',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lidDirectMessage(string $messageId): array
    {
        return [
            'key' => $this->lidKey($messageId),
            'pushName' => 'Rafael Zapata',
            'remoteJid' => '168968509780173@lid',
            'messageBody' => 'buenas tardes',
            'message' => ['extendedTextMessage' => ['text' => 'buenas tardes']],
        ];
    }

    private function runEvent(string $event, array $data): array
    {
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $this->receiver()->getId(),
            'url' => $this->receiver()->getUrl(),
            'headers' => [],
            'payload' => [
                'event' => $event,
                'sessionId' => 'test-session',
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

    private function groupTextMessage(string $messageId): array
    {
        return [
            'key' => [
                'id' => $messageId,
                'fromMe' => false,
                'remoteJid' => self::GROUP_JID,
                'participant' => '900000000000001@lid',
                'participantPn' => '15550001111@s.whatsapp.net',
                'addressingMode' => 'lid',
                'participantLid' => '900000000000001@lid',
                'cleanedParticipantPn' => '15550001111',
            ],
            'pushName' => 'Alex Rivera',
            'message' => [
                'messageContextInfo' => ['messageSecret' => 'x'],
                'extendedTextMessage' => ['text' => 'Press release body for the fixture'],
            ],
            'remoteJid' => self::GROUP_JID,
        ];
    }
}
