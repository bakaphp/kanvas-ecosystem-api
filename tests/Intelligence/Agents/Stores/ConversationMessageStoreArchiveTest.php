<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Stores;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasChatHistory;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\ReadsMessageContents;

class ConversationMessageStoreArchiveTest extends TestCase
{
    use ReadsMessageContents;
    use MakesAgents;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private ConversationMessageStore $store;

    private string $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = $this->makeAgentFor($user);

        $this->thread = (string) Str::uuid();
        $this->store = new ConversationMessageStore(
            app: $app,
            company: $company,
            user: $user,
            agentClass: 'Stub\\Agent',
            sessionId: $this->thread,
            agent: $agent,
        );

        foreach ([
            new UserMessage('one'),
            new AssistantMessage('two'),
            new UserMessage('three'),
            new AssistantMessage('four'),
        ] as $message) {
            $this->store->append($this->thread, $message);
        }
    }

    public function testArchivedRowsLeaveTheActiveWindowButStayInTheTranscript(): void
    {
        $this->store->archiveMessages($this->thread, [$this->idOf('one'), $this->idOf('two')]);

        $this->assertEqualsCanonicalizing(['three', 'four'], $this->contents($this->store->loadActive($this->thread)));
        $this->assertSame(4, $this->rows()->count());
        $this->assertSame(2, $this->rows()->whereNotNull('archived_at')->count());
    }

    public function testClearArchivesTheWholeThreadInsteadOfDeletingIt(): void
    {
        $this->store->clear($this->thread);

        $this->assertSame([], $this->store->loadActive($this->thread));
        $this->assertSame(4, $this->rows()->whereNotNull('archived_at')->count());
    }

    /**
     * A second user turn in a row folds into the first in the working window, but it is its own row and
     * must stay active: the fold recorded the message's pre-persist id, the row carries the bare one.
     */
    public function testATurnFoldedIntoThePreviousOneKeepsItsRowActive(): void
    {
        $history = new KanvasChatHistory(
            $this->store,
            $this->thread,
            50_000,
            KanvasHistoryTrimmer::make(),
        );

        $history->addMessage(new UserMessage('five'));
        $history->addMessage(new UserMessage('six'));

        $this->assertSame(6, $this->rows()->count());
        $this->assertSame(0, $this->rows()->whereNotNull('archived_at')->count(), 'Nothing was trimmed, so nothing is archived');
        $this->assertContains('six', $this->contents($this->store->loadActive($this->thread)));
    }

    public function testReappendingAnArchivedMessageBringsItBackActiveWithoutADuplicateRow(): void
    {
        $kept = $this->messageWithContent('four');

        $this->store->clear($this->thread);
        $this->store->append($this->thread, $kept);

        $this->assertSame(['four'], $this->contents($this->store->loadActive($this->thread)));
        $this->assertSame(4, $this->rows()->count());
    }

    private function idOf(string $content): string
    {
        return $this->messageWithContent($content)->getId();
    }

    /**
     * Rows written inside one millisecond share a uuid7 prefix, so the load order is not insertion order.
     */
    private function messageWithContent(string $content): Message
    {
        foreach ($this->store->loadActive($this->thread) as $message) {
            if ((string) $message->getContent() === $content) {
                return $message;
            }
        }

        $this->fail("No active message reads '{$content}'");
    }

    private function rows(): Builder
    {
        return DB::connection('intelligence')
            ->table('agent_conversation_messages')
            ->where('conversation_id', $this->store->conversationId($this->thread));
    }
}
