<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards KANVAS-ECOSYSTEM-6F1: a stored conversation that outgrows the model's input limit must be cut
 * before it is replayed, or the thread fails on every further turn (Gemini 400, "input token count
 * exceeds the maximum number of tokens allowed 1048576"). KanvasChatHistory trims on load and on every
 * add (the first inference of a turn sends the loaded history before any add, see
 * KanvasChatHistoryTest), and KanvasHistoryTrimmer is the trimmer every Kanvas agent opens its history
 * with: fold first, then the stock cut.
 */
class LoadedHistoryTrimTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    /**
     * @return list<Message>
     */
    private function conversation(int $turns, int $chars = 2000): array
    {
        $messages = [];

        for ($i = 0; $i < $turns; $i++) {
            $messages[] = new UserMessage("u{$i} " . str_repeat('q', $chars));
            $messages[] = new AssistantMessage("a{$i} " . str_repeat('r', $chars));
        }

        return $messages;
    }

    /**
     * @param list<Message> $messages
     */
    private function estimate(array $messages): int
    {
        $counter = new TokenCounter();

        return array_reduce(
            $messages,
            static fn (int $carry, Message $message): int => $carry + $counter->count($message),
            0,
        );
    }

    /**
     * The history as an agent opens it: a store that already holds the thread, the Kanvas trimmer, and
     * one live add — the first add is what runs the trim.
     *
     * @param list<Message> $loaded
     */
    private function history(array $loaded, int $contextWindow): ChatHistory
    {
        $store = new InMemoryMessageStore();

        foreach ($loaded as $message) {
            $store->append('thread', $message);
        }

        return new ChatHistory(
            $store,
            'thread',
            $contextWindow,
            KanvasHistoryTrimmer::make(),
        );
    }

    public function testLoadedHistoryIsCutToTheContextWindow(): void
    {
        $messages = $this->conversation(100);
        $this->assertGreaterThan(50_000, $this->estimate($messages), 'Fixture must overflow the window.');

        $kept = KanvasHistoryTrimmer::make()->trim($messages, 5_000);

        $this->assertLessThan(200, count($kept), 'The whole conversation must not reach the provider.');
        // One message of slack: adjustTrimIndex may step back a turn to land on a user message.
        $this->assertLessThanOrEqual(
            5_000 + $this->estimate([$messages[0]]),
            $this->estimate($kept),
        );
    }

    public function testTheNewestTurnsAreTheOnesKept(): void
    {
        $messages = $this->conversation(100);

        $kept = KanvasHistoryTrimmer::make()->trim($messages, 5_000);
        $last = $kept[array_key_last($kept)];

        $this->assertStringStartsWith('a99 ', (string) $last->getContent());
        $this->assertStringStartsNotWith('u0 ', (string) $kept[0]->getContent());
    }

    public function testTrimmedHistoryStillStartsWithAUserTurn(): void
    {
        $kept = KanvasHistoryTrimmer::make()->trim($this->conversation(100), 5_000);

        $this->assertSame(MessageRole::USER->value, $kept[0]->getRole());
    }

    public function testAConversationInsideTheWindowKeepsEveryTurn(): void
    {
        $this->assertCount(6, KanvasHistoryTrimmer::make()->trim($this->conversation(3, 100), 50_000));
    }

    public function testLeadingAssistantTurnsAreDroppedAndSameRoleTurnsMerge(): void
    {
        $kept = KanvasHistoryTrimmer::make()->trim([
            new AssistantMessage('I opened this thread'),
            new UserMessage('first'),
            new UserMessage('second'),
            new AssistantMessage('answer'),
        ], 50_000);

        $this->assertCount(2, $kept);
        $this->assertSame("first\n\nsecond", (string) $kept[0]->getContent());
    }

    public function testDuplicateTurnsDoNotConcatenateOnRebuild(): void
    {
        $kept = KanvasHistoryTrimmer::make()->trim([
            new UserMessage('quote me the Civic'),
            new AssistantMessage('Here is the quote.'),
            new AssistantMessage('Here is the quote.'),
        ], 50_000);

        $this->assertSame('Here is the quote.', (string) $kept[1]->getContent());
    }

    /**
     * The rebuild and the live-add path share one fold, so a turn persisted twice against the same
     * entity collapses whether it arrives from storage or mid-turn.
     */
    public function testALiveTurnFoldsIntoTheTrailingTurnWithoutDuplicating(): void
    {
        $history = $this->history([
            new UserMessage('quote me the Civic'),
            new AssistantMessage('Here is the quote.'),
        ], 50_000);

        $history->addMessage(new AssistantMessage('Here is the quote.'));
        $history->addMessage(new AssistantMessage('Shall I send it?'));

        $kept = $history->getMessages();

        $this->assertCount(2, $kept);
        $this->assertSame("Here is the quote.\n\nShall I send it?", (string) $kept[1]->getContent());
    }

    /**
     * setContents() resets the block list, so an attachment on the folded-in turn (a PDF on an incoming
     * @mention) would be silently dropped and the model would answer "I can't see the file".
     */
    public function testMediaBlocksSurviveAFold(): void
    {
        $withImage = new UserMessage('here is the photo');
        $withImage->addContent(new ImageContent('data:image/png;base64,aGVsbG8=', SourceType::BASE64, 'image/png'));

        $kept = KanvasHistoryTrimmer::fold([
            new UserMessage('first'),
            $withImage,
        ]);

        $this->assertCount(1, $kept);
        $this->assertSame("first\n\nhere is the photo", (string) $kept[0]->getContent());
        $this->assertInstanceOf(ImageContent::class, $kept[0]->getImage());
    }

    /**
     * The row cap bounds memory, not context — so it has to sit far above whatever the token trim keeps.
     */
    public function testConversationStoreOnlyHydratesTheNewestRows(): void
    {
        /** @var Apps $app */
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $sessionUuid = Str::uuid()->toString();
        $conversationId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->getId(),
            'agent_id' => $agent->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'title' => $sessionUuid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cap = (int) new ReflectionClass(ConversationMessageStore::class)
            ->getConstant('MAX_LOADED_ROWS');
        $total = $cap + 100;

        $rows = [];
        for ($i = 0; $i < $total; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversationId,
                'user_id' => $user->getId(),
                'agent' => 'Stub\\Agent',
                'role' => $i % 2 === 0 ? MessageRole::USER->value : MessageRole::ASSISTANT->value,
                'is_public' => 1,
                'content' => "turn {$i}",
                'attachments' => '[]',
                'steps' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'created_at' => now()->addSeconds($i),
                'updated_at' => now()->addSeconds($i),
            ];
        }
        DB::connection('intelligence')->table('agent_conversation_messages')->insert($rows);

        $store = new ConversationMessageStore(
            app: $app,
            company: $company,
            user: $user,
            agentClass: 'Stub\\Agent',
            sessionId: $sessionUuid,
            agent: $agent,
        );

        $loaded = $store->loadActive($sessionUuid);

        // Strictly alternating one-word turns, so nothing folds and the token window never binds:
        // exactly the newest $cap rows survive, oldest-first from row 100.
        $this->assertCount($cap, $loaded);
        $this->assertSame('turn ' . ($total - $cap), (string) $loaded[0]->getContent());
        $this->assertSame('turn ' . ($total - 1), (string) $loaded[array_key_last($loaded)]->getContent());
    }
}
