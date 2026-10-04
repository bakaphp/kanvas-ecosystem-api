<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\CompanyMemoryRetrieval;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;

/**
 * Two companies in one app share one store. A search from company A never returns company B's turn,
 * not even with a forged thread id, and a per-run filter can narrow the scope but never widen it.
 */
class CompanyMemoryRetrievalTest extends TestCase
{
    private const int APP = 1;
    private const int COMPANY_A = 101;
    private const int COMPANY_B = 202;

    public function testACompanyOnlyRecallsItsOwnTurns(): void
    {
        $store = $this->storeWith(
            $this->memory("User: Who signs the Acme contract?\nAssistant: Ana signs it on Friday.", self::COMPANY_A, thread: 'thread-shared'),
            $this->memory("User: Who signs the Globex contract?\nAssistant: Bob signs it on Monday.", self::COMPANY_B, thread: 'thread-shared'),
        );

        $documents = $this->retrieval($store, self::COMPANY_A)->retrieve(new UserMessage('Who signs the contract?'));

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('Ana signs it on Friday', $documents[0]->getContent());
        $this->assertStringNotContainsString('Bob', $documents[0]->getContent());
    }

    public function testAPerRunFilterNarrowsAndNeverWidens(): void
    {
        $store = $this->storeWith(
            $this->memory("User: Where is the office?\nAssistant: Santo Domingo, Piantini.", self::COMPANY_A),
            $this->memory('Parking is validated at the front desk.', self::COMPANY_A, kind: 'memory'),
            $this->memory("User: Where is the office?\nAssistant: Miami, Brickell.", self::COMPANY_B),
        );
        $retrieval = $this->retrieval($store, self::COMPANY_A);

        $narrowed = $retrieval->retrieve(new UserMessage('Where is the office?'), Filter::eq('source_type', 'memory'));
        $widened = $retrieval->retrieve(new UserMessage('Where is the office?'), Filter::eq('companies_id', self::COMPANY_B));

        $this->assertCount(1, $narrowed);
        $this->assertStringContainsString('Parking is validated', $narrowed[0]->getContent());
        $this->assertSame([], $widened, 'A filter for another company is AND-ed with the tenant pin, so nothing matches');
    }

    public function testAHitIsLabelledWithItsKindAndDate(): void
    {
        $store = $this->storeWith(
            $this->memory("User: Budget?\nAssistant: Approved at 40K.", self::COMPANY_A, createdAt: mktime(12, 0, 0, 9, 28, 2026)),
            $this->memory('Call the vendor before the 15th.', self::COMPANY_A, createdAt: mktime(12, 0, 0, 9, 29, 2026), kind: 'memory'),
            $this->memory('Policy FAQ chunk', self::COMPANY_A, kind: 'file'),
        );

        $contents = array_map(
            static fn (Document $document): string => $document->getContent(),
            $this->retrieval($store, self::COMPANY_A)->retrieve(new UserMessage('budget'))
        );

        $this->assertContains("[Earlier conversation, 2026-09-28] User: Budget?\nAssistant: Approved at 40K.", $contents);
        $this->assertContains('[Saved memory, 2026-09-29] Call the vendor before the 15th.', $contents);
        $this->assertCount(2, $contents, 'Uploaded knowledge is the knowledge retrieval\'s job, not memory\'s');
    }

    public function testAnEmptyQuestionRetrievesNothing(): void
    {
        $store = $this->storeWith($this->memory("User: x\nAssistant: y", self::COMPANY_A));

        $this->assertSame([], $this->retrieval($store, self::COMPANY_A)->retrieve(new UserMessage('   ')));
    }

    private function retrieval(MemoryVectorStore $store, int $companyId): CompanyMemoryRetrieval
    {
        return new CompanyMemoryRetrieval(
            store: $store,
            embeddings: new ConstantEmbeddingsProvider(),
            appId: self::APP,
            companyId: $companyId,
            topK: 5,
        );
    }

    private function storeWith(Document ...$documents): MemoryVectorStore
    {
        $store = SharedCompanyMemory::newStore();
        $store->addDocuments(new ConstantEmbeddingsProvider()->embedDocuments($documents));

        return $store;
    }

    private function memory(
        string $content,
        int $companyId,
        ?int $createdAt = null,
        string $kind = 'conversation',
        string $thread = 'thread-a'
    ): Document {
        return SharedCompanyMemory::document(
            $content,
            $kind,
            self::APP,
            $companyId,
            $createdAt,
            thread: $thread,
        );
    }
}
