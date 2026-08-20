<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * PR2: a group thread files silently against a Channel — no Lead anywhere — each speaker gets
 * their own People, and consecutive messages chain into one burst.
 *
 * The timeline mirrors the production capture (group 15550001111-1700000000@g.us,
 * a 90-second window): a seven-part MEDIA_ALBUM with an 11s internal gap, then
 * a press release followed by a photo 22s later.
 */
final class CreateGroupMessageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private const string GROUP_JID = '15550001111-1700000000@g.us';
    private const string ALBUM_ID = '3AALBUMPARENT000001';

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

    public function testAllowListedGroupFilesAgainstAChannelAndNeverCreatesALead(): void
    {
        $this->allowGroup();
        $leadsBefore = Lead::query()->count();

        $result = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $filed = $result['result']['messages'][0];

        $this->assertNotNull($filed['message_id']);
        $this->assertSame(self::GROUP_JID, $filed['chat_jid']);
        $this->assertSame('group', $filed['conversation_type']);

        $message = Message::findOrFail($filed['message_id']);
        $channel = Channel::findOrFail($filed['channel_id']);

        $this->assertSame('wa-group-' . Str::slug(self::GROUP_JID), $channel->slug);
        $this->assertInstanceOf(Channel::class, $message->entity());
        $this->assertSame($channel->getId(), $message->entity()->getId());
        $this->assertSame($leadsBefore, Lead::query()->count(), 'A group must never produce a Lead');
    }

    /**
     * A room has many voices and one stored history, so the agent has to be told who spoke.
     */
    public function testSpeakerNameIsPrefixedIntoTheStoredContent(): void
    {
        $this->allowGroup();

        $result = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $message = Message::findOrFail($result['result']['messages'][0]['message_id']);

        $this->assertStringStartsWith('Alex Rivera: ', (string) $message->message['content']);
    }

    public function testEachSpeakerResolvesToTheirOwnPeopleKeyedOnTheirLid(): void
    {
        $this->allowGroup();

        $first = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $second = $this->ingest($this->groupText(Str::uuid()->toString(), 'Sam Okafor', '900000000000002', '15550002222'));

        $firstPeople = $first['result']['messages'][0]['people_id'];
        $secondPeople = $second['result']['messages'][0]['people_id'];

        $this->assertNotNull($firstPeople);
        $this->assertNotSame($firstPeople, $secondPeople);
        $this->assertSame('900000000000001', (string) People::findOrFail($firstPeople)->get('whatsapp_lid'));
    }

    /**
     * The same speaker across a burst must land on one People, not one per part. Resolution goes
     * through SyncPeopleByThirdPartyCustomFieldAction so parallel workers handling two parts of
     * the same album can't both insert.
     */
    public function testTheSameSpeakerResolvesToOnePeopleAcrossMessages(): void
    {
        $this->allowGroup();

        $first = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $second = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));

        $this->assertSame(
            $first['result']['messages'][0]['people_id'],
            $second['result']['messages'][0]['people_id']
        );
    }

    /**
     * A lid is not a phone number — filing 900000000000001 as a cellphone would poison every phone
     * lookup in the company.
     */
    public function testSpeakerWithNoDisclosedPhoneGetsNoContactRow(): void
    {
        $this->allowGroup();

        $payload = $this->groupText(Str::uuid()->toString(), 'Anon');
        unset($payload['key']['participantPn'], $payload['key']['cleanedParticipantPn']);

        $result = $this->ingest($payload);
        $people = People::findOrFail($result['result']['messages'][0]['people_id']);

        $this->assertCount(0, $people->contacts()->get());
        $this->assertSame('900000000000001', (string) $people->get('whatsapp_lid'));
    }

    public function testGroupOutsideTheAllowListFilesNothing(): void
    {
        $messagesBefore = Message::query()->count();

        $result = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));

        $this->assertSame([], $result['result']['messages']);
        $this->assertSame($messagesBefore, Message::query()->count());
    }

    /**
     * The captured album spans 22 seconds with an 11-second internal gap — no idle window would
     * hold it together, which is why the shared parentMessageKey decides.
     */
    public function testAlbumPartsChainOntoOneHeadDespiteTheElevenSecondGap(): void
    {
        $this->allowGroup();

        $head = $this->ingestAt('13:27:15', $this->groupAlbumImage('3AALBUM000000000001'));
        $gap = $this->ingestAt('13:27:26', $this->groupAlbumImage('3AALBUM000000000003'));
        $tail = $this->ingestAt('13:27:37', $this->groupAlbumImage('3AALBUM000000000004'));

        $headId = $head['result']['messages'][0]['message_id'];

        $this->assertNull($head['result']['messages'][0]['parent_id']);
        $this->assertSame($headId, $gap['result']['messages'][0]['parent_id']);
        $this->assertSame($headId, $tail['result']['messages'][0]['parent_id'], 'Album parts chain flat, not nested');
    }

    /**
     * a press release and its photo carry no messageAssociation at all — only the idle
     * window binds them.
     */
    public function testArticleAndItsLaterPhotoCollapseIntoOneBurst(): void
    {
        $this->allowGroup();

        $article = $this->ingestAt('13:28:30', $this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $photo = $this->ingestAt('13:28:52', $this->groupImage(Str::uuid()->toString(), 'Alex Rivera'));

        $this->assertSame(
            $article['result']['messages'][0]['message_id'],
            $photo['result']['messages'][0]['parent_id']
        );
    }

    public function testSilenceLongerThanTheIdleWindowStartsANewBurst(): void
    {
        $this->allowGroup();

        $first = $this->ingestAt('13:28:30', $this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $later = $this->ingestAt('13:29:30', $this->groupText(Str::uuid()->toString(), 'Alex Rivera'));

        $this->assertNotNull($first['result']['messages'][0]['message_id']);
        $this->assertNull($later['result']['messages'][0]['parent_id']);
    }

    public function testAnotherSpeakerClosesThePreviousBurst(): void
    {
        $this->allowGroup();

        $this->ingestAt('13:28:30', $this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $interruption = $this->ingestAt('13:28:35', $this->groupText(Str::uuid()->toString(), 'Sam Okafor', '900000000000002', '15550002222'));
        $resumed = $this->ingestAt('13:28:40', $this->groupText(Str::uuid()->toString(), 'Alex Rivera'));

        $this->assertNull($interruption['result']['messages'][0]['parent_id']);
        $this->assertNull($resumed['result']['messages'][0]['parent_id'], 'A closed burst does not reopen');
    }

    /**
     * Video is filed so the channel history stays complete, but nothing sends it to the agent yet.
     */
    public function testVideoIsFiledButTaggedAsUnprocessed(): void
    {
        $this->allowGroup();

        $result = $this->ingest($this->groupVideo(Str::uuid()->toString()));
        $message = Message::findOrFail($result['result']['messages'][0]['message_id']);

        $this->assertSame('whatsapp-video', $message->messageType->verb);
        $this->assertTrue($message->tags()->where('name', 'media-not-processed')->exists());
    }

    private function allowGroup(): void
    {
        $receiver = $this->receiver();
        $receiver->configuration = [
            ...$receiver->configuration,
            GroupConfigEnum::ALLOWED_GROUP_JIDS->value => [self::GROUP_JID],
        ];
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

    private function groupText(
        string $messageId,
        string $pushName,
        string $lid = '900000000000001',
        string $phone = '15550001111'
    ): array {
        return [
            ...$this->groupEnvelope($messageId, $pushName, $lid, $phone),
            'message' => [
                'messageContextInfo' => ['messageSecret' => 'x'],
                'extendedTextMessage' => ['text' => 'Press release body for the fixture'],
            ],
        ];
    }

    private function groupImage(string $messageId, string $pushName): array
    {
        return [
            ...$this->groupEnvelope($messageId, $pushName, '900000000000001', '15550001111'),
            'message' => [
                'imageMessage' => [
                    'url' => 'https://mmg.whatsapp.net/o1/v/t24/f2/m000/EXAMPLE',
                    'mimetype' => 'image/jpeg',
                    'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHky',
                ],
                'messageContextInfo' => ['messageSecret' => 'y'],
            ],
        ];
    }

    private function groupVideo(string $messageId): array
    {
        return [
            ...$this->groupEnvelope($messageId, 'Sam Okafor', '900000000000002', '15550002222'),
            'message' => [
                'videoMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/t62.0000-00/000000000',
                    'mimetype' => 'video/mp4',
                    'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHkz',
                ],
            ],
        ];
    }

    private function groupAlbumImage(string $messageId): array
    {
        return [
            ...$this->groupEnvelope($messageId, 'Sam Okafor', '900000000000002', '15550002222'),
            'message' => [
                'imageMessage' => [
                    'url' => 'https://mmg.whatsapp.net/o1/v/t24/f2/m000/EXAMPLE',
                    'mimetype' => 'image/jpeg',
                    'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHk9',
                ],
                'messageContextInfo' => [
                    'messageSecret' => 'z',
                    'messageAssociation' => [
                        'associationType' => 'MEDIA_ALBUM',
                        'parentMessageKey' => ['id' => self::ALBUM_ID],
                    ],
                ],
            ],
        ];
    }

    private function groupEnvelope(string $messageId, string $pushName, string $lid, string $phone): array
    {
        return [
            'key' => [
                'id' => $messageId,
                'fromMe' => false,
                'remoteJid' => self::GROUP_JID,
                'participant' => $lid . '@lid',
                'participantPn' => $phone . '@s.whatsapp.net',
                'addressingMode' => 'lid',
                'participantLid' => $lid . '@lid',
                'cleanedParticipantPn' => $phone,
            ],
            'pushName' => $pushName,
            'remoteJid' => self::GROUP_JID,
        ];
    }
}
