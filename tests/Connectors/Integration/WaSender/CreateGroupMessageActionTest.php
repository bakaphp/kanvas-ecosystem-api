<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\CreateGroupMessageAction;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Connectors\WaSender\Services\GroupBurstService;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

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
    private const string JPEG_THUMBNAIL = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

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
    /**
     * The production sequence behind "images never reach the agent" (app 60, 2026-08-21 02:43):
     * a caption followed by a three-part album. The album's first part has no sibling filed yet,
     * so before the speaker fallback it opened its own burst and the agent wrote the article
     * having never seen the photos.
     */
    public function testAnAlbumChainsOntoTheCaptionThatPrecedesIt(): void
    {
        $this->allowGroup();

        $caption = $this->ingestAt('02:43:22', $this->groupText(Str::uuid()->toString(), 'Yhanelly Rodriguez'));
        $first = $this->ingestAt('02:43:22', $this->groupAlbumImage(Str::uuid()->toString(), 'Yhanelly Rodriguez'));
        $second = $this->ingestAt('02:43:22', $this->groupAlbumImage(Str::uuid()->toString(), 'Yhanelly Rodriguez'));
        $third = $this->ingestAt('02:43:23', $this->groupAlbumImage(Str::uuid()->toString(), 'Yhanelly Rodriguez'));

        $headId = $caption['result']['messages'][0]['message_id'];

        $this->assertNull($caption['result']['messages'][0]['parent_id']);
        $this->assertSame($headId, $first['result']['messages'][0]['parent_id'], 'Album part 1 must join the caption');
        $this->assertSame($headId, $second['result']['messages'][0]['parent_id']);
        $this->assertSame($headId, $third['result']['messages'][0]['parent_id']);

        // What the agent is handed: one turn holding the caption and all three photos.
        $burst = GroupBurstService::messagesFor($headId);
        $this->assertCount(4, $burst);
    }

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

    /**
     * Each speaker gets their own burst, so an interruption does not split a flurry.
     *
     * This replaced "any other speaker closes the burst", which was a consequence of deriving the
     * head from the channel's newest row rather than a deliberate rule — and under parallel
     * delivery it meant whoever happened to be visible decided the grouping. A turn is one
     * person's flurry: in a busy room two reporters posting alternately should not shred each
     * other's messages into single-message bursts.
     */
    public function testEachSpeakerKeepsTheirOwnBurstThroughAnInterruption(): void
    {
        $this->allowGroup();

        $alex = $this->ingestAt('13:28:30', $this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $interruption = $this->ingestAt('13:28:35', $this->groupText(Str::uuid()->toString(), 'Sam Okafor', '900000000000002', '15550002222'));
        $resumed = $this->ingestAt('13:28:40', $this->groupText(Str::uuid()->toString(), 'Alex Rivera'));

        $this->assertNull($interruption['result']['messages'][0]['parent_id'], 'Sam opens his own burst');
        $this->assertSame(
            $alex['result']['messages'][0]['message_id'],
            $resumed['result']['messages'][0]['parent_id'],
            "Alex's own flurry stays one turn"
        );
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

    /**
     * Chaining is a read-then-write and deliveries arrive as parallel jobs, so it is serialised on
     * a per-channel lock. Holding that lock stands in for "another worker is mid-chain": the
     * message must still be filed, just unchained, rather than the delivery failing.
     *
     * Deliberately NOT using ingestAt(): `Lock::block()` derives its timeout from `now()`, so a
     * frozen Carbon makes the wait loop spin forever.
     */
    public function testAContendedChannelStillFilesTheMessageUnchained(): void
    {
        $this->allowGroup();

        $first = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $channelId = $first['result']['messages'][0]['channel_id'];

        $lock = Cache::lock('wasender:burst-chain:' . $channelId, 10);
        $this->assertTrue($lock->get(), 'precondition: the test holds the channel lock');

        try {
            $second = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        } finally {
            $lock->release();
        }

        $filed = $second['result']['messages'][0];

        $this->assertNotNull($filed['message_id'], 'A contended delivery must still be filed');
        $this->assertNull($filed['parent_id'], 'It degrades to its own head rather than failing');
    }

    /**
     * The same sequence with the lock free chains — proving the test above measures the lock and
     * not some unrelated reason the second message found no head.
     */
    public function testTheSameSequenceChainsWhenTheLockIsFree(): void
    {
        $this->allowGroup();

        $first = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));
        $second = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));

        $this->assertSame(
            $first['result']['messages'][0]['message_id'],
            $second['result']['messages'][0]['parent_id']
        );
    }

    /**
     * The follower hands the message back through `$candidate->parent`, which threw
     * CircularReferenceException and lost the delivery (Sentry KANVAS-ECOSYSTEM-68E).
     */
    public function testAMessageAlreadyAdoptedAsHeadIsNeverHandedBackToItself(): void
    {
        $this->allowGroup();

        $headPayload = $this->groupText(Str::uuid()->toString(), 'Alex Rivera');
        $head = $this->ingest($headPayload);
        $follower = $this->ingest($this->groupText(Str::uuid()->toString(), 'Alex Rivera'));

        $headMessage = Message::findOrFail($head['result']['messages'][0]['message_id']);
        $channel = Channel::findOrFail($head['result']['messages'][0]['channel_id']);

        $this->assertSame(
            $headMessage->getId(),
            $follower['result']['messages'][0]['parent_id'],
            'precondition: the follower adopted this message as its head'
        );

        $inbound = InboundMessage::fromWebhookMessage($headPayload);
        $this->assertNotNull($inbound);

        $resolved = new GroupBurstService(
            $channel,
            BurstConfigEnum::BURST_IDLE_SECONDS->getInt($this->receiver()),
            BurstConfigEnum::BURST_MAX_SECONDS->getInt($this->receiver()),
        )->resolveHead($headMessage, $inbound);

        $this->assertNull($resolved, 'A message can never be its own burst head');
    }

    /**
     * No model takes video — `nativeKind()` returns null for `video/*` and the attachment is
     * dropped before the prompt. WhatsApp ships a poster frame inside the payload, so storing it
     * as an image is the only way the agent sees what was posted, and it costs no extra fetch.
     */
    public function testAVideoStoresItsPosterFrameSoTheAgentCanSeeIt(): void
    {
        $this->allowGroup();

        $result = $this->ingest($this->groupVideo(Str::uuid()->toString(), self::JPEG_THUMBNAIL));
        $message = Message::findOrFail($result['result']['messages'][0]['message_id']);

        $this->assertSame('whatsapp-video', $message->messageType->verb);
        $this->assertCount(
            1,
            $message->attachmentUrls()['images'],
            'the poster frame must reach the agent as an image'
        );
    }

    /**
     * A video without a poster frame must not blow up the delivery.
     */
    public function testAVideoWithNoPosterFrameIsStillFiled(): void
    {
        $this->allowGroup();

        $result = $this->ingest($this->groupVideo(Str::uuid()->toString()));
        $message = Message::findOrFail($result['result']['messages'][0]['message_id']);

        $this->assertSame('whatsapp-video', $message->messageType->verb);
        $this->assertSame([], $message->attachmentUrls()['images']);
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

    /**
     * A 39MB clip 400s against WaSender's 25MB decrypt limit every time — an answer, not a fault, and
     * it was reported 12 times in two days (KANVAS-ECOSYSTEM-68N). The skip stays visible on the
     * message instead.
     */
    public function testMediaTheProviderRefusesIsFlaggedWithoutReachingSentry(): void
    {
        Exceptions::fake();

        $message = $this->recordFailure(new WaSenderRefusedException(
            'Decrypted media size (38.89 MB) exceeds the 25 MB storage limit.',
            400
        ));

        Exceptions::assertNothingReported();
        $this->assertTrue($message->tags()->where('name', 'media-not-downloaded')->exists());
        $this->assertStringContainsString(
            'exceeds the 25 MB storage limit',
            (string) $message->get('media_download_error')
        );
    }

    /**
     * A missing api key is a genuine misconfiguration, so it must not be swallowed along with the
     * provider's refusals.
     */
    public function testAMediaFailureThatIsNotAProviderRefusalStillReports(): void
    {
        Exceptions::fake();

        $this->recordFailure(new ValidationException('Wasender configuration is missing'));

        Exceptions::assertReported(fn (ValidationException $e): bool => $e->getMessage() === 'Wasender configuration is missing');
    }

    private function recordFailure(Throwable $failure): Message
    {
        $message = Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['message' => ['content' => 'clip caption']]);

        // Classification reads only the message — a real constructor would drag in a receiver, a
        // channel and a decoded payload none of it touches.
        $action = new ReflectionClass(CreateGroupMessageAction::class)->newInstanceWithoutConstructor();

        new ReflectionMethod($action, 'recordMediaFailure')->invoke($action, $message, $failure);

        return $message;
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

    private function groupVideo(string $messageId, ?string $jpegThumbnail = null): array
    {
        return [
            ...$this->groupEnvelope($messageId, 'Sam Okafor', '900000000000002', '15550002222'),
            'message' => [
                'videoMessage' => [
                    'url' => 'https://mmg.whatsapp.net/v/t62.0000-00/000000000',
                    'mimetype' => 'video/mp4',
                    'mediaKey' => 'ZXhhbXBsZS1tZWRpYS1rZXktZm9yLXRlc3RzLW9ubHkz',
                    ...($jpegThumbnail !== null ? ['jpegThumbnail' => $jpegThumbnail] : []),
                ],
            ],
        ];
    }

    private function groupAlbumImage(string $messageId, string $pushName = 'Sam Okafor'): array
    {
        return [
            ...($pushName === 'Sam Okafor'
                ? $this->groupEnvelope($messageId, 'Sam Okafor', '900000000000002', '15550002222')
                : $this->groupEnvelope($messageId, $pushName, '900000000000001', '15550001111')),
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
