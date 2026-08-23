<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConfigEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConversationModeEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupReplyModeEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Jobs\ProcessGroupBurstJob;
use Kanvas\Connectors\WaSender\Services\ContactService;
use Kanvas\Connectors\WaSender\Services\GroupBurstService;
use Kanvas\Connectors\WaSender\Services\GroupMentionService;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\SyncWorkflowStub;
use RuntimeException;
use Tests\Stubs\Intelligence\StructuredNeuronAgentStub;
use Tests\TestCase;

/**
 * PR3: a burst closes once, on a debounce, and the reply is gated separately from the processing.
 */
final class ProcessGroupBurstJobTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow', 'intelligence'];

    private const string GROUP_JID = '15550001111-1700000000@g.us';
    private const string OWN_LID = '999888777666555';
    private const string OWN_PHONE = '15559990000';

    private ?Carbon $clock = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);

        // Anchored to real now, not a fixed date: the debounce token's TTL is computed against the
        // clock at ingest, so a test that time-travels into the past and then reads the cache back
        // at real now finds everything already expired.
        $this->clock = now();
        $this->groupAgent = $this->makeGroupAgent();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Every part re-arms the debounce, so three messages queue three jobs but only the last one
     * still matches the token when it fires.
     */
    public function testEveryPartArmsTheDebounceAndOnlyTheLastTokenSurvives(): void
    {
        Queue::fake();
        $this->allowGroup();

        $first = $this->ingestAt(0, $this->groupText('Alex Rivera'));
        $second = $this->ingestAt(5, $this->groupText('Alex Rivera'));

        $headId = $first['result']['messages'][0]['message_id'];

        $this->assertSame($headId, $second['result']['messages'][0]['parent_id']);

        $dispatched = [];
        Queue::assertPushed(
            ProcessGroupBurstJob::class,
            function (ProcessGroupBurstJob $job) use ($headId, &$dispatched): bool {
                $dispatched[] = $job;

                return $job->burstHeadId === $headId;
            }
        );

        $this->assertCount(2, $dispatched, 'Each part re-arms the close');
        $this->assertNotSame($dispatched[0]->token, $dispatched[1]->token);
        $this->assertSame(
            Cache::get(ProcessGroupBurstJob::cacheKey($headId)),
            $dispatched[1]->token,
            'Only the newest token is current'
        );
    }

    public function testASupersededJobDoesNothing(): void
    {
        Queue::fake();
        $this->allowGroup();

        $result = $this->ingestAt(0, $this->groupText('Alex Rivera'));
        $headId = $result['result']['messages'][0]['message_id'];
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);

        new ProcessGroupBurstJob(
            app(Apps::class),
            $this->receiver(),
            $channel,
            $headId,
            'a-token-from-an-earlier-part'
        )->handle();

        $this->assertNotNull(
            Cache::get(ProcessGroupBurstJob::cacheKey($headId)),
            'A superseded job must leave the burst armed for the winner'
        );
    }

    public function testTheWinningJobClosesTheBurstAndClearsTheToken(): void
    {
        Queue::fake();
        $this->allowGroup();

        $result = $this->ingestAt(0, $this->groupText('Alex Rivera'));
        $headId = $result['result']['messages'][0]['message_id'];
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $token = Cache::get(ProcessGroupBurstJob::cacheKey($headId));

        new ProcessGroupBurstJob(
            app(Apps::class),
            $this->receiver(),
            $channel,
            $headId,
            (string) $token
        )->handle();

        $this->assertNull(Cache::get(ProcessGroupBurstJob::cacheKey($headId)));
    }

    /**
     * `$tries = 2`, and the burst does work after the agent has already answered — the workflow fire
     * above all. The token is what makes a burst run once, so it has to survive the retry: claiming
     * it only on the way out left it armed when something threw mid-run, so the retry re-entered,
     * ran the agent again and filed a SECOND reply — two replies, two published posts from one
     * burst (prod 736602 / 736603).
     */
    public function testARetryAfterAMidRunFailureDoesNotFileASecondAgentReply(): void
    {
        Queue::fake();
        $this->useStructuredAgent();
        // NEVER keeps the reply off the wire; the agent still runs and still files its answer, which
        // is the thing that duplicated.
        $this->allowGroup(GroupReplyModeEnum::NEVER);

        $result = $this->ingestAt(0, $this->groupText('Alex Rivera'));
        $headId = $result['result']['messages'][0]['message_id'];
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $token = (string) Cache::get(ProcessGroupBurstJob::cacheKey($headId));

        $job = new ProcessGroupBurstJob(
            app(Apps::class),
            $this->receiver(),
            $this->explodingChannel($channel),
            $headId,
            $token
        );

        try {
            $job->handle();
            $this->fail('The channel stub must blow up after the agent answered');
        } catch (RuntimeException) {
            // The queue would hand this to attempt two.
        }

        $this->assertSame(1, $this->agentReplyCount($channel), 'The burst answered once before failing');

        $job->handle();

        $this->assertSame(1, $this->agentReplyCount($channel), 'The retry must not answer a second time');
    }

    /**
     * A real channel row that throws the first time the burst announces itself — the shape of any
     * failure between the agent answering and the job finishing.
     */
    private function explodingChannel(Channel $channel): Channel
    {
        $exploding = new class () extends Channel {
            public bool $armed = true;

            public function fireWorkflow(
                string $event,
                bool $async = true,
                array $params = []
            ): ?SyncWorkflowStub {
                // Only the burst's own announcement, which is the one call that happens after the
                // agent has already answered. Blowing up on any channel event would land inside the
                // responder's own catch and never reach the retry.
                if ($this->armed && $event === WorkflowEnum::AFTER_ADDING_MESSAGE_TO_GROUP_CHANNEL->value) {
                    $this->armed = false;

                    throw new RuntimeException('workflow fire failed');
                }

                return null;
            }
        };

        $exploding->setRawAttributes($channel->getAttributes(), true);
        $exploding->exists = true;

        return $exploding;
    }

    private function agentReplyCount(Channel $channel): int
    {
        return $channel->messages()
            ->get()
            ->filter(fn (Message $message): bool => (bool) ($message->message['from_ia'] ?? false))
            ->count();
    }

    /**
     * A handler that actually returns a structured answer, so the burst produces a reply message to
     * count rather than failing inside the agent turn.
     */
    private function useStructuredAgent(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $this->groupAgent->type->update([
            'provider' => 'neuron',
            'handler' => StructuredNeuronAgentStub::class,
        ]);
    }

    /**
     * The article and its photo reach the agent as one turn, speaker-attributed.
     */
    public function testTheBurstComposesIntoOneAttributedPrompt(): void
    {
        Queue::fake();
        $this->allowGroup();

        $first = $this->ingestAt(0, $this->groupText('Alex Rivera', 'Press release body'));
        $this->ingestAt(10, $this->groupText('Alex Rivera', 'Y la foto va aparte'));

        $headId = $first['result']['messages'][0]['message_id'];
        $prompt = GroupBurstService::promptFor(GroupBurstService::messagesFor($headId));

        $this->assertSame(
            "Alex Rivera: Press release body\n\nAlex Rivera: Y la foto va aparte",
            $prompt
        );
    }

    /**
     * A reply that always lands exactly N seconds after the last message is a metronome. Jitter is
     * additive, so it can never shorten the window below the point where a burst still collapses.
     */
    public function testTheCloseDelayIsJitteredWithinBounds(): void
    {
        Queue::fake();
        $this->allowGroup();
        $this->setJitter(12);

        $delays = [];

        for ($i = 0; $i < 8; $i++) {
            $armedAt = $this->clock->copy()->addSeconds($i * 120);
            Carbon::setTestNow($armedAt);

            $this->ingestAt($i * 120, $this->groupText('Alex Rivera', 'nota ' . $i));

            $pushed = [];
            Queue::assertPushed(ProcessGroupBurstJob::class, function (ProcessGroupBurstJob $job) use (&$pushed): bool {
                $pushed[] = $job;

                return true;
            });

            $delays[] = end($pushed)->delay->getTimestamp() - $armedAt->getTimestamp();
        }

        // Plain chatter, so the window is the full idle one.
        $window = BurstConfigEnum::BURST_IDLE_SECONDS->getInt($this->receiver);

        foreach ($delays as $delay) {
            $this->assertGreaterThanOrEqual($window, $delay, 'Jitter must never shorten the burst window');
            $this->assertLessThanOrEqual($window + 12, $delay);
        }

        $this->assertGreaterThan(1, count(array_unique($delays)), 'A fixed delay is as robotic as no delay');
    }

    public function testJitterOfZeroGivesTheExactConfiguredWindow(): void
    {
        Queue::fake();
        $this->allowGroup();
        $this->setJitter(0);

        $armedAt = $this->clock->copy();
        Carbon::setTestNow($armedAt);
        $this->ingestAt(0, $this->groupText('Alex Rivera'));

        $pushed = [];
        Queue::assertPushed(ProcessGroupBurstJob::class, function (ProcessGroupBurstJob $job) use (&$pushed): bool {
            $pushed[] = $job;

            return true;
        });

        $this->assertSame(
            BurstConfigEnum::BURST_IDLE_SECONDS->getInt($this->receiver),
            end($pushed)->delay->getTimestamp() - $armedAt->getTimestamp()
        );
    }

    private function setJitter(int $seconds): void
    {
        $receiver = $this->receiver();
        $receiver->configuration = [
            ...$receiver->configuration,
            BurstConfigEnum::BURST_JITTER_SECONDS->value => $seconds,
        ];
        $receiver->saveOrFail();
    }

    public function testAMentionShortensTheCloseWindow(): void
    {
        Queue::fake();
        $this->allowGroup();

        $this->ingestAt(0, $this->groupText('Alex Rivera'));
        $this->ingestAt(5, $this->groupTextMentioning('Alex Rivera'));

        $delays = [];
        Queue::assertPushed(ProcessGroupBurstJob::class, function (ProcessGroupBurstJob $job) use (&$delays): bool {
            $delays[] = $job->delay;

            return true;
        });

        $this->assertCount(2, $delays);
        $this->assertLessThan(
            $delays[0]->getTimestamp(),
            $delays[1]->getTimestamp(),
            'A mention must not wait the full idle window'
        );
    }

    public function testMentionOfOurLidCountsAsAddressed(): void
    {
        Queue::fake();
        $this->allowGroup();

        $result = $this->ingestAt(0, $this->groupTextMentioning('Alex Rivera'));
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $messages = GroupBurstService::messagesFor($result['result']['messages'][0]['message_id']);

        $this->assertTrue(
            new GroupMentionService($this->receiver(), $channel)->isAddressed($messages)
        );
    }

    public function testPlainChatterIsNotAddressed(): void
    {
        Queue::fake();
        $this->allowGroup();

        $result = $this->ingestAt(0, $this->groupText('Alex Rivera'));
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $messages = GroupBurstService::messagesFor($result['result']['messages'][0]['message_id']);

        $this->assertFalse(
            new GroupMentionService($this->receiver(), $channel)->isAddressed($messages)
        );
    }

    /**
     * Quoting the agent is addressing it just as plainly as an @.
     */
    public function testQuotingAnAgentMessageCountsAsAddressed(): void
    {
        Queue::fake();
        $this->allowGroup();

        $result = $this->ingestAt(0, $this->groupText('Alex Rivera'));
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);

        $agentReply = Message::findOrFail($result['result']['messages'][0]['message_id']);
        $agentReply->addMessage([
            'message_id' => 'AGENT-REPLY-1',
            'from_ia' => true,
        ]);

        $quoting = $this->ingestAt(10, $this->groupTextQuoting('Sam Okafor', 'AGENT-REPLY-1'));
        $messages = GroupBurstService::messagesFor($quoting['result']['messages'][0]['message_id']);

        $this->assertTrue(
            new GroupMentionService($this->receiver(), $channel)->isAddressed($messages)
        );
    }

    /**
     * messages.upsert echoes outgoing messages back, so arming a burst on one would have the agent
     * answer its own reply, forever. It is still filed — the channel history has to be complete,
     * and it is where we learn our own lid.
     */
    public function testOurOwnGroupMessageIsFiledButNeverArmsABurst(): void
    {
        Queue::fake();
        $this->allowGroup();

        $payload = $this->groupText('Our Session');
        $payload['key']['fromMe'] = true;
        $payload['key']['participant'] = self::OWN_LID . '@lid';
        $payload['key']['participantLid'] = self::OWN_LID . '@lid';

        $result = $this->ingestAt(0, $payload);

        $this->assertNotNull(
            $result['result']['messages'][0]['message_id'],
            'Our own message is still filed'
        );
        Queue::assertNotPushed(ProcessGroupBurstJob::class);
    }

    /**
     * The bootstrap: in `mention` mode the agent won't speak until it sees a mention, and under lid
     * addressing it can't see one until it knows its own lid — which learning-from-`fromMe` only
     * gives it after it has spoken. Resolving from our own phone breaks that circle.
     */
    public function testOwnLidIsResolvedFromOurPhoneWhenNothingIsCached(): void
    {
        Queue::fake();
        $this->allowGroup();
        $this->forgetOwnLid();

        $contacts = new class (self::OWN_LID . '@lid') extends ContactService {
            public function __construct(private string $lid)
            {
            }

            public function getLidFromPhoneNumber(string $phoneNumber): ?string
            {
                return $this->lid;
            }
        };

        $result = $this->ingestAt(0, $this->groupTextMentioning('Alex Rivera'));
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $messages = GroupBurstService::messagesFor($result['result']['messages'][0]['message_id']);
        $this->assertTrue(
            new GroupMentionService($this->receiver(), $channel, $contacts)->isAddressed($messages),
            'A mention must be visible before the agent has ever spoken'
        );
        $this->assertSame(
            self::OWN_LID,
            $this->receiver()->refresh()->configuration[GroupMentionService::OWN_LID_KEY] ?? null,
            'The resolved lid is cached, so the lookup happens once'
        );
    }

    /**
     * A number WhatsApp has no lid for must not mean an HTTP round-trip on every burst — the
     * failed lookup backs off, so the second burst never calls out again.
     */
    public function testAFailedLidLookupBacksOffInsteadOfRetryingEveryBurst(): void
    {
        Queue::fake();
        $this->allowGroup();
        $this->forgetOwnLid();

        $contacts = new class () extends ContactService {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function getLidFromPhoneNumber(string $phoneNumber): ?string
            {
                $this->calls++;

                return null;
            }
        };

        $result = $this->ingestAt(0, $this->groupTextMentioning('Alex Rivera'));
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $messages = GroupBurstService::messagesFor($result['result']['messages'][0]['message_id']);

        new GroupMentionService($this->receiver(), $channel, $contacts)->isAddressed($messages);
        new GroupMentionService($this->receiver(), $channel, $contacts)->isAddressed($messages);

        $this->assertSame(1, $contacts->calls, 'The second burst must be served by the backoff');
    }

    /**
     * The session's own lid is only knowable from a message it sent itself.
     */
    public function testOwnLidIsLearnedFromOurOwnGroupMessage(): void
    {
        Queue::fake();
        $this->allowGroup();
        $this->forgetOwnLid();

        $payload = $this->groupText('Our Session');
        $payload['key']['fromMe'] = true;
        $payload['key']['participant'] = self::OWN_LID . '@lid';
        $payload['key']['participantLid'] = self::OWN_LID . '@lid';

        $this->ingestAt(0, $payload);

        $this->assertSame(
            self::OWN_LID,
            $this->receiver()->refresh()->configuration[GroupMentionService::OWN_LID_KEY] ?? null
        );
    }

    public function testNeverModeNeverReplies(): void
    {
        Queue::fake();
        $this->allowGroup(GroupReplyModeEnum::NEVER);

        $result = $this->ingestAt(0, $this->groupTextMentioning('Alex Rivera'));
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $headId = $result['result']['messages'][0]['message_id'];

        // No agent configured, so the burst closes without an agent turn — the point here is that
        // it closes cleanly rather than throwing on a mention it must not answer.
        new ProcessGroupBurstJob(
            app(Apps::class),
            $this->receiver(),
            $channel,
            $headId,
            (string) Cache::get(ProcessGroupBurstJob::cacheKey($headId))
        )->handle();

        $this->assertNull(Cache::get(ProcessGroupBurstJob::cacheKey($headId)));
        $this->assertSame(0, Message::query()->where('parent_id', $headId)->where('is_deleted', 0)->count());
    }

    /**
     * The quoted author's lid rides on the quote itself (capture 2026-08-19), so quoting the agent
     * counts even when the quoted message was never filed on our side.
     */
    public function testQuotingOurLidCountsAsAddressedWithoutTheQuotedMessageOnFile(): void
    {
        Queue::fake();
        $this->allowGroup();

        $payload = $this->groupText('Alex Rivera', 'De acuerdo.');
        $payload['message']['extendedTextMessage']['contextInfo'] = [
            'stanzaId' => 'NEVER-FILED-ON-OUR-SIDE',
            'participant' => self::OWN_LID . '@lid',
            'quotedMessage' => ['conversation' => 'an agent reply sent outside ingestion'],
        ];

        $result = $this->ingestAt(0, $payload);
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);
        $messages = GroupBurstService::messagesFor($result['result']['messages'][0]['message_id']);

        $this->assertTrue(
            new GroupMentionService($this->receiver(), $channel)->isAddressed($messages)
        );
    }

    /**
     * PR5: an assistant 1:1 rides the same burst job — detected off the head message's
     * conversation_type, no mention detection, agent resolved from the connection's own agent_id.
     */
    public function testDirectAssistantBurstClosesAndClearsItsToken(): void
    {
        Queue::fake();
        $receiver = $this->receiver();
        $receiver->configuration = [
            ...$receiver->configuration,
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->value => DirectConversationModeEnum::ASSISTANT->value,
            'agent_id' => $this->groupAgent->getId(),
        ];
        $receiver->saveOrFail();

        $result = $this->ingestAt(0, $this->directText('summarize my day'));
        $filed = $result['result']['messages'][0];
        $headId = $filed['message_id'];
        $channel = Channel::findOrFail($filed['channel_id']);

        $this->assertSame('assistant', $filed['mode']);

        new ProcessGroupBurstJob(
            app(Apps::class),
            $this->receiver(),
            $channel,
            $headId,
            (string) Cache::get(ProcessGroupBurstJob::cacheKey($headId))
        )->handle();

        $this->assertNull(Cache::get(ProcessGroupBurstJob::cacheKey($headId)), 'The direct burst closes cleanly');
    }

    /**
     * A connection configured for groups first, then switched to assistant mode without a separate
     * DM agent, still has an agent to run — otherwise the burst closes silently with no reply and
     * no error.
     */
    public function testDirectBurstFallsBackToTheGroupAgentWhenNoDedicatedAgentIsSet(): void
    {
        Queue::fake();
        $receiver = $this->receiver();
        $receiver->configuration = [
            ...$receiver->configuration,
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->value => DirectConversationModeEnum::ASSISTANT->value,
            GroupConfigEnum::GROUP_AGENT_ID->value => $this->groupAgent->getId(),
        ];
        $receiver->saveOrFail();

        $result = $this->ingestAt(0, $this->directText('what is on my calendar'));
        $headId = $result['result']['messages'][0]['message_id'];
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);

        $this->assertArrayNotHasKey('agent_id', $receiver->configuration, 'No dedicated DM agent is configured');

        new ProcessGroupBurstJob(
            app(Apps::class),
            $this->receiver(),
            $channel,
            $headId,
            (string) Cache::get(ProcessGroupBurstJob::cacheKey($headId))
        )->handle();

        $this->assertNull(Cache::get(ProcessGroupBurstJob::cacheKey($headId)));
    }

    public function testDirectNeverModePostsNoReply(): void
    {
        Queue::fake();
        $receiver = $this->receiver();
        $receiver->configuration = [
            ...$receiver->configuration,
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->value => DirectConversationModeEnum::ASSISTANT->value,
            DirectConfigEnum::DIRECT_REPLY_MODE->value => GroupReplyModeEnum::NEVER->value,
            'agent_id' => $this->groupAgent->getId(),
        ];
        $receiver->saveOrFail();

        $result = $this->ingestAt(0, $this->directText('just noting this down'));
        $headId = $result['result']['messages'][0]['message_id'];
        $channel = Channel::findOrFail($result['result']['messages'][0]['channel_id']);

        new ProcessGroupBurstJob(
            app(Apps::class),
            $this->receiver(),
            $channel,
            $headId,
            (string) Cache::get(ProcessGroupBurstJob::cacheKey($headId))
        )->handle();

        $this->assertNull(Cache::get(ProcessGroupBurstJob::cacheKey($headId)));
        $this->assertSame(0, Message::query()->where('parent_id', $headId)->where('is_deleted', 0)->count());
    }

    private function directText(string $text): array
    {
        return [
            'key' => [
                'id' => Str::uuid()->toString(),
                'fromMe' => false,
                'remoteJid' => '18095550001@s.whatsapp.net',
            ],
            'pushName' => 'Max Owner',
            'remoteJid' => '18095550001@s.whatsapp.net',
            'message' => ['extendedTextMessage' => ['text' => $text]],
        ];
    }

    private function allowGroup(GroupReplyModeEnum $mode = GroupReplyModeEnum::MENTION): void
    {
        $receiver = $this->receiver();
        $receiver->configuration = [
            ...$receiver->configuration,
            GroupConfigEnum::ALLOWED_GROUP_JIDS->value => [self::GROUP_JID],
            GroupConfigEnum::GROUP_REPLY_MODE->value => $mode->value,
            GroupConfigEnum::GROUP_AGENT_ID->value => $this->groupAgent->getId(),
            GroupMentionService::OWN_LID_KEY => self::OWN_LID,
        ];
        $receiver->saveOrFail();
    }

    /**
     * Mention detection reads our own number off the group agent — that is what the lid lookup is
     * keyed on.
     */
    private function makeGroupAgent(): Agent
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'agent_type_id' => AgentType::factory()->create(['apps_id' => $app->getId()]),
                'user_id' => $user->getId(),
            ]);

        $agent->set(ConnectionFieldEnum::PHONE_NUMBER->value, self::OWN_PHONE);

        return $agent;
    }

    private ?Agent $groupAgent = null;

    private function forgetOwnLid(): void
    {
        $receiver = $this->receiver();
        $configuration = $receiver->configuration;
        unset($configuration[GroupMentionService::OWN_LID_KEY]);
        $receiver->configuration = $configuration;
        $receiver->saveOrFail();
    }

    /**
     * @param int $secondsFromStart offset inside the burst window, mirroring the capture's spacing
     */
    private function ingestAt(int $secondsFromStart, array $messageData): array
    {
        Carbon::setTestNow($this->clock->copy()->addSeconds($secondsFromStart));

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

    private function groupText(string $pushName, string $text = 'Press release body'): array
    {
        return [
            ...$this->envelope($pushName),
            'message' => ['extendedTextMessage' => ['text' => $text]],
        ];
    }

    private function groupTextMentioning(string $pushName): array
    {
        return [
            ...$this->envelope($pushName),
            'message' => [
                'extendedTextMessage' => [
                    'text' => 'que opinas de esto?',
                    'contextInfo' => [
                        'mentionedJid' => [
                            self::OWN_LID . '@lid',
                            '111111111111111@lid',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function groupTextQuoting(string $pushName, string $quotedId): array
    {
        return [
            ...$this->envelope($pushName),
            'message' => [
                'extendedTextMessage' => [
                    'text' => 'gracias',
                    'contextInfo' => ['stanzaId' => $quotedId],
                ],
            ],
        ];
    }

    private function envelope(string $pushName): array
    {
        $lid = $pushName === 'Sam Okafor' ? '900000000000002' : '900000000000001';
        $phone = $pushName === 'Sam Okafor' ? '15550002222' : '15550001111';

        return [
            'key' => [
                'id' => Str::uuid()->toString(),
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
