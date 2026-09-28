<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use ReflectionMethod;
use Tests\TestCase;

/**
 * An agent turn's reply is delivered into the chat store after the turn has usually recorded it there
 * itself. Mirroring it again showed it twice; skipping it unconditionally loses it for the turns that
 * record elsewhere. The write is skipped only when the reply is verifiably already the latest message.
 */
class AppendAssistantMessageDedupeTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function testAReplyTheTurnJustRecordedIsNotWrittenTwice(): void
    {
        $session = $this->sessionWithReply('5 of 10 done');

        $this->assertNull($this->append($session, '5 of 10 done', unlessJustRecorded: true));
        $this->assertSame(1, $this->assistantMessages($session));
    }

    /** A turn that recorded elsewhere leaves something else latest here, so the reply must still land. */
    public function testADifferentLatestMessageStillGetsTheReply(): void
    {
        $session = $this->sessionWithReply('an earlier answer');

        $this->assertNotNull($this->append($session, '5 of 10 done', unlessJustRecorded: true));
        $this->assertSame(2, $this->assistantMessages($session));
    }

    /** A daily task can repeat yesterday's exact text; matching an old message would drop today's reply. */
    public function testAnIdenticalButOldMessageDoesNotBlockTheReply(): void
    {
        $session = $this->sessionWithReply('No new leads today');
        DB::connection('intelligence')->table('agent_conversation_messages')
            ->whereIn('conversation_id', $this->conversationIds($session))
            ->update(['created_at' => now()->subDay()]);

        $this->assertNotNull($this->append($session, 'No new leads today', unlessJustRecorded: true));
    }

    /** The agent's own history stores the raw reply; the delivery carries the extracted text. */
    public function testARawEnvelopeMatchesItsExtractedText(): void
    {
        $session = $this->sessionWithReply('{"response":"5 of 10 done"}');

        $this->assertNull($this->append($session, '5 of 10 done', unlessJustRecorded: true));
    }

    /** Plan announcements and scheduling confirmations never opted in, and keep writing every time. */
    public function testCallersThatDidNotOptInAlwaysWrite(): void
    {
        $session = $this->sessionWithReply('Scheduled.');

        $this->assertNotNull($this->append($session, 'Scheduled.', unlessJustRecorded: false));
        $this->assertSame(2, $this->assistantMessages($session));
    }

    private function sessionWithReply(string $reply): string
    {
        $session = (string) Str::uuid();

        new KanvasConversationStore()->logTurn(
            userId: auth()->user()->getId(),
            sessionId: $session,
            agentClass: 'TestAgent',
            userMessage: 'Set up the ten workflows',
            assistantResponse: $reply,
        );

        return $session;
    }

    private function append(string $session, string $content, bool $unlessJustRecorded): ?string
    {
        [$appsId, $companiesId] = new ReflectionMethod(KanvasConversationStore::class, 'tenantIds')
            ->invoke(new KanvasConversationStore());

        return new KanvasConversationStore()->appendAssistantMessageForSession(
            appsId: $appsId,
            companiesId: $companiesId,
            sessionId: $session,
            agentClass: 'TestAgent',
            content: $content,
            unlessJustRecorded: $unlessJustRecorded,
        );
    }

    private function assistantMessages(string $session): int
    {
        return DB::connection('intelligence')->table('agent_conversation_messages')
            ->whereIn('conversation_id', $this->conversationIds($session))
            ->where('role', 'assistant')
            ->count();
    }

    /**
     * @return list<string>
     */
    private function conversationIds(string $session): array
    {
        return DB::connection('intelligence')->table('agent_conversations')
            ->where('title', $session)
            ->pluck('id')
            ->all();
    }
}
