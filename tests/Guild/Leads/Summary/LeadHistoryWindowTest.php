<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Leads\Summary\LeadHistoryWindow;
use Kanvas\Guild\Leads\Summary\LeadSummaryState;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasChatHistory;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Neuron\Memory\ConversationMemoryNode;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\CompanyMemoryRetrieval;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;
use Tests\Traits\MakesLeadConversations;

final class LeadHistoryWindowTest extends TestCase
{
    use DatabaseTransactions;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    /**
     * Past the fold cap a newer same-role turn replaces an older one: input 1, 2, 3, 4, 5 keeps 1, 2, 4, 5.
     * An "oldest kept row" boundary reads that as nothing dropped and message 3 is never summarized.
     */
    public function testAHoleInTheMiddleIsReportedAsDropped(): void
    {
        $lead = $this->makeLead();
        $ids = array_map(fn (string $text): int => $this->leadMessage($lead, $text)->getId(), ['one', 'two', 'three', 'four', 'five']);

        $store = new InMemoryMessageStore();
        $store->append('t', new UserMessage('hello')->setId('social:' . $ids[0]));
        $store->append('t', new AssistantMessage('hi')->setId('social:' . $ids[1]));
        $store->append('t', new UserMessage(str_repeat('x', 410))->setId('social:' . $ids[2]));
        $store->append('t', new UserMessage(str_repeat('y', 410))->setId('social:' . $ids[3]));
        $store->append('t', new AssistantMessage('ok')->setId('social:' . $ids[4]));

        $history = new KanvasChatHistory(
            $store,
            't',
            200,
            KanvasHistoryTrimmer::make(),
        );
        $history->getMessages();

        $window = LeadHistoryWindow::of($lead, $history->window(), new LeadSummaryState(through: $ids[1]));

        $this->assertSame([$ids[0], $ids[1], $ids[3], $ids[4]], $window->retained);
        $this->assertSame([$ids[2]], $window->dropped);
        $this->assertSame([$ids[2]], $window->uncovered, 'Above the marker, so the catch-up has to cover it');
    }

    /**
     * Only an exchange whose two Social messages are both still on screen is left out of recall; a hole,
     * an unstamped reply and a legacy entry all stay recallable.
     */
    public function testRecallLeavesOutOnlyExchangesProvablyInTheWindow(): void
    {
        $store = SharedCompanyMemory::newStore();
        $memory = static fn (string $content, array $ids): Document => SharedCompanyMemory::document(
            $content,
            ConversationMemoryNode::SOURCE_TYPE,
            1,
            2,
            extra: $ids,
        );
        $store->addDocuments(new ConstantEmbeddingsProvider()->embedDocuments([
            $memory('on screen', ['inbound_message_id' => 10, 'reply_message_id' => 11]),
            $memory('in the hole', ['inbound_message_id' => 12, 'reply_message_id' => 13]),
            $memory('reply never stamped', ['inbound_message_id' => 14]),
            $memory('legacy', []),
        ]));

        $recalled = new CompanyMemoryRetrieval(
            store: $store,
            embeddings: new ConstantEmbeddingsProvider(),
            appId: 1,
            companyId: 2,
            retainedMessageIds: [10, 11, 13, 14],
        )->retrieve(new UserMessage('what did we say?'));

        $contents = array_map(static fn ($document): string => $document->getContent(), $recalled);
        $this->assertCount(3, $contents);
        foreach (['in the hole', 'reply never stamped', 'legacy'] as $kept) {
            $this->assertNotEmpty(array_filter($contents, static fn (string $content): bool => str_contains($content, $kept)), $kept);
        }
    }
}
