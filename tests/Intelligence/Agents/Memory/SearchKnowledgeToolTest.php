<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Kanvas\Intelligence\Agents\Neuron\Tools\System\SearchKnowledgeTool;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Tests\TestCase;

/**
 * The on-demand half of recall: one tool over the composite retrieval the automatic recall uses, so
 * the model has one place to look and each hit says where it came from.
 */
class SearchKnowledgeToolTest extends TestCase
{
    public function testResultsCarryTheirSourceAndTheLabelledContent(): void
    {
        $retrieval = $this->retrievalReturning([
            new Document('[Company document] CDJR lot: 178 New County Rd')->setSourceType('agent_document'),
            new Document('[Record history, 2026-10-06] whats your address?')->setSourceType('Kanvas\\Guild\\Leads\\Models\\Lead'),
            new Document('[Earlier conversation, 2026-09-21] User: credit app')->setMetadata(['source_type' => 'conversation']),
        ]);

        $result = new SearchKnowledgeTool($retrieval)->__invoke(query: 'CDJR lot address');

        $this->assertSame(3, $result['count']);
        $this->assertSame(['company_document', 'record_history', 'conversation'], array_column($result['results'], 'source'));
        $this->assertSame('[Company document] CDJR lot: 178 New County Rd', $result['results'][0]['content']);
        $this->assertSame('CDJR lot address', $retrieval->queries[0]);
    }

    public function testAPeriodBecomesACreatedAtFilterAndABadDateIsAnError(): void
    {
        $retrieval = $this->retrievalReturning([]);

        $miss = new SearchKnowledgeTool($retrieval)->__invoke(query: 'launch date', since: '2026-09-01', until: '2026-09-30');
        $this->assertSame(0, $miss['count']);
        $this->assertStringContainsString('Nothing matches', $miss['message']);
        $this->assertInstanceOf(FilterExpression::class, $retrieval->filters[0]);

        $bad = new SearchKnowledgeTool($retrieval)->__invoke(query: 'anything', since: 'not a date at all, really');
        $this->assertStringContainsString('must be dates', $bad['message']);
    }

    public function testAThirdSearchInATurnIsToldToAnswerInstead(): void
    {
        $tool = new SearchKnowledgeTool($this->retrievalReturning([new Document('[Company document] Office: Santo Domingo')]));

        foreach (['office', 'office address'] as $query) {
            $this->assertSame(1, (clone $tool)->__invoke(query: $query)['count'], 'NeuronAI runs each call on a clone; the budget still counts the turn');
        }

        $third = (clone $tool)->__invoke(query: 'headquarters');

        $this->assertSame(0, $third['count']);
        $this->assertStringContainsString('already searched 2 times', $third['message']);
        $this->assertSame(SearchKnowledgeTool::MAX_SEARCHES_PER_TURN, 2);
    }

    /**
     * @param list<Document> $documents
     */
    private function retrievalReturning(array $documents): RetrievalInterface
    {
        return new class ($documents) implements RetrievalInterface {
            /** @var list<string> */
            public array $queries = [];

            /** @var list<FilterExpression|null> */
            public array $filters = [];

            /** @param list<Document> $documents */
            public function __construct(private readonly array $documents)
            {
            }

            public function retrieve(Message $query, ?FilterExpression $filters = null): array
            {
                $this->queries[] = (string) $query->getContent();
                $this->filters[] = $filters;

                return $this->documents;
            }
        };
    }
}
