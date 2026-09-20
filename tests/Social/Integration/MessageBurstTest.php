<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Concerns\ChainsInboundBursts;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Social\Messages\Jobs\FlushMessageBurstJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageBurstService;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Social\RecordingBurstHandler;
use Tests\TestCase;

/**
 * The channel-agnostic half of burst handling: a flurry of inbound messages collapses into one
 * turn, and only the last part of it survives to close the burst.
 *
 * Every channel that answers inbound messages depends on this — without it, two messages sent back
 * to back produce two independent agent turns and the customer gets two replies.
 */
final class MessageBurstTest extends TestCase
{
    use ChainsInboundBursts;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'social'];

    private Apps $currentApp;
    private Channel $channel;
    private Carbon $clock;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        RecordingBurstHandler::reset();

        $this->currentApp = app(Apps::class);
        $this->channel = $this->makeChannel();

        // Anchored to real now, not a fixed date: the debounce token's TTL is computed against the
        // clock at ingest, so a test that time-travels into the past and then reads the cache back
        // at real now finds everything already expired.
        $this->clock = now();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testASecondMessageInsideTheWindowChainsOntoTheFirst(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'can you help me');
        $tail = $this->ingestAt(5, 'sorry, I meant the blue one');

        $this->assertNull($head->parent_id, 'The first message of a burst is its head');
        $this->assertSame($head->getId(), $tail->refresh()->parent_id);
        $this->assertSame($head->uuid, $tail->parent_unique_id);
    }

    /**
     * `parent_id` carries social comment threading as well as burst chaining, and consumers that read
     * it cannot tell the two apart — `total_children` counts a flurry as replies, the Typesense
     * `children` summary nests each part inside the head while indexing it separately, and
     * `MessageOwnerChildNotificationActivity` fires "someone replied to your message" for a part
     * nobody replied to. The tag is what makes that answerable.
     */
    public function testBurstPartsAreTaggedSoTheyAreNotMistakenForReplies(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'first');
        $tail = $this->ingestAt(5, 'second');

        $this->assertFalse(
            $head->tags()->where('name', MessageBurstService::BURST_PART_TAG)->exists(),
            'The head opens the turn, it is not a continuation of one'
        );
        $this->assertTrue(
            $tail->tags()->where('name', MessageBurstService::BURST_PART_TAG)->exists()
        );
    }

    public function testEveryPartReArmsTheDebounceAndOnlyTheNewestTokenSurvives(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'first');
        $this->ingestAt(5, 'second');

        $dispatched = [];
        Queue::assertPushed(
            FlushMessageBurstJob::class,
            function (FlushMessageBurstJob $job) use (&$dispatched): bool {
                $dispatched[] = $job;

                return true;
            }
        );

        $this->assertCount(2, $dispatched, 'Each part re-arms the close');
        $this->assertSame([$head->getId(), $head->getId()], array_map(
            fn (FlushMessageBurstJob $job): int => $job->burstHeadId,
            $dispatched
        ));
        $this->assertNotSame($dispatched[0]->token, $dispatched[1]->token);
        $this->assertSame(
            Cache::get(FlushMessageBurstJob::cacheKey($head->getId())),
            $dispatched[1]->token,
            'Only the newest token is current'
        );
    }

    public function testASupersededJobDoesNothingAndLeavesTheBurstArmed(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'first');
        $this->ingestAt(5, 'second');

        $this->flush($head->getId(), 'a-token-from-an-earlier-part');

        $this->assertSame(0, RecordingBurstHandler::$runs);
        $this->assertNotNull(
            Cache::get(FlushMessageBurstJob::cacheKey($head->getId())),
            'A superseded job must leave the burst armed for the winner'
        );
    }

    public function testTheWinningJobAnswersTheWholeBurstOnceAndClearsTheToken(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'can you help me');
        $tail = $this->ingestAt(5, 'sorry, I meant the blue one');

        $token = Cache::get(FlushMessageBurstJob::cacheKey($head->getId()));
        $this->flush($head->getId(), $token);

        $this->assertSame(1, RecordingBurstHandler::$runs);
        $this->assertSame([$head->getId(), $tail->getId()], RecordingBurstHandler::$messageIds);
        $this->assertSame(
            "can you help me\n\nsorry, I meant the blue one",
            RecordingBurstHandler::$prompt,
            'The agent has to see the flurry as one turn, not just its first line'
        );
        $this->assertNull(Cache::get(FlushMessageBurstJob::cacheKey($head->getId())));
    }

    /**
     * Re-entry is not hypothetical: FlushMessageBurstJob has `$tries = 2`, so a throw after the
     * handler has already replied would run the whole turn a second time if the token outlived it.
     */
    public function testReplayingTheWinningJobDoesNotAnswerTwice(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'hello');
        $token = Cache::get(FlushMessageBurstJob::cacheKey($head->getId()));

        $this->flush($head->getId(), $token);
        $this->flush($head->getId(), $token);

        $this->assertSame(1, RecordingBurstHandler::$runs);
    }

    public function testAMessageOutsideTheIdleWindowOpensItsOwnBurst(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'first');
        $later = $this->ingestAt(45, 'a new question entirely');

        $this->assertNull(
            $later->refresh()->parent_id,
            'Past the idle window the conversation has moved on and deserves its own turn'
        );
        $this->assertNotSame($head->getId(), $later->getId());
        $this->assertNotNull(Cache::get(FlushMessageBurstJob::cacheKey($later->getId())));
    }

    /**
     * Two senders in the same channel must not be collapsed into one another's turn, which is what
     * a channel-wide "newest row" heuristic would do.
     */
    public function testDifferentCorrelationKeysDoNotShareABurst(): void
    {
        Queue::fake();

        $mine = $this->ingestAt(0, 'first', correlation: 'sms:+15550001111');
        $theirs = $this->ingestAt(5, 'unrelated', correlation: 'sms:+15559998888');

        $this->assertNull($theirs->refresh()->parent_id);
        $this->assertNotSame($mine->getId(), $theirs->getId());
    }

    /**
     * The idle window alone cannot end a burst that never goes quiet — someone typing every ten
     * seconds would keep re-arming it forever and never get an answer. `maxSeconds` is the ceiling
     * that closes it regardless, measured from the head rather than from the last part.
     */
    public function testAConversationThatNeverGoesQuietStillFlushesAtTheCeiling(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'first', maxSeconds: 30);
        $withinBoth = $this->ingestAt(15, 'second', maxSeconds: 30);
        $atTheCeiling = $this->ingestAt(30, 'third', maxSeconds: 30);

        // Every gap is inside the 15s idle window, so only the ceiling can end this burst.
        $pastTheCeiling = $this->ingestAt(45, 'fourth', maxSeconds: 30);

        $this->assertSame($head->getId(), $withinBoth->refresh()->parent_id);
        $this->assertSame($head->getId(), $atTheCeiling->refresh()->parent_id);
        $this->assertNull(
            $pastTheCeiling->refresh()->parent_id,
            'Past maxSeconds the burst closes and the next message opens its own'
        );

        // Both have to stay armed. Opening the second burst overwrites the head registry, and if it
        // took the first burst's token with it those three messages would never be answered.
        $this->assertNotNull(Cache::get(FlushMessageBurstJob::cacheKey($head->getId())));
        $this->assertNotNull(Cache::get(FlushMessageBurstJob::cacheKey($pastTheCeiling->getId())));
    }

    /**
     * Every part of the burst was soft-deleted between arming and flushing. There is nothing to
     * answer, and the handler must not run on an empty collection.
     */
    public function testAnEmptyBurstNeverReachesTheHandler(): void
    {
        Queue::fake();

        $head = $this->ingestAt(0, 'hello');
        $token = Cache::get(FlushMessageBurstJob::cacheKey($head->getId()));

        $head->is_deleted = 1;
        $head->saveOrFail();

        $this->flush($head->getId(), $token);

        $this->assertSame(0, RecordingBurstHandler::$runs);
    }

    private function ingestAt(
        int $offsetSeconds,
        string $body,
        string $correlation = 'sms:+15550001111',
        int $maxSeconds = 90
    ): Message {
        Carbon::setTestNow($this->clock->copy()->addSeconds($offsetSeconds));

        $message = Message::factory()->create([
            'apps_id' => $this->currentApp->getId(),
            'message' => ['content' => $body, 'from_me' => false],
        ]);

        $this->channel->addMessage($message);

        $this->fileIntoBurst(
            $this->currentApp,
            $this->channel,
            $message,
            $this->policy([$correlation], $maxSeconds),
            RecordingBurstHandler::class
        );

        return $message;
    }

    private function flush(int $headId, ?string $token): void
    {
        new FlushMessageBurstJob(
            $this->currentApp,
            $this->channel,
            $headId,
            (string) $token,
            RecordingBurstHandler::class
        )->handle();
    }

    /**
     * @param list<string> $correlationKeys
     */
    private function policy(array $correlationKeys, int $maxSeconds = 90): BurstPolicy
    {
        return new BurstPolicy(
            correlationKeys: $correlationKeys,
            chainIdleSeconds: 15,
            closeIdleSeconds: 15,
            maxSeconds: $maxSeconds,
        );
    }

    private function makeChannel(): Channel
    {
        $user = auth()->user();

        return new CreateChannelAction(
            new ChannelData(
                apps: $this->currentApp,
                companies: $user->getCurrentCompany(),
                users: $user,
                entity_id: (string) fake()->unique()->randomNumber(8),
                entity_namespace: Users::class,
                name: 'burst-test',
                description: 'Message burst test channel',
                slug: (string) fake()->unique()->uuid(),
            )
        )->execute();
    }
}
