<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SearchKnowledgeTool;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use Mockery;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Tests\TestCase;
use Typesense\Client;
use Typesense\MultiSearch;

/**
 * The rows the real store hands to the ranking are whole Typesense documents, content and ids
 * included, and Neuron refuses some of those keys as Document metadata. Unit tests that build hits by
 * hand never carried them; the first prod turn did (KANVAS-ECOSYSTEM-6JX). This drives the store's own
 * search mapping, from a Typesense multi_search body, through the ranking and into the tool.
 */
class KnowledgeRetrievalOnStoreRowsTest extends TestCase
{
    public function testARealStoreRowSurvivesRankingAndReachesTheToolLabelled(): void
    {
        $store = $this->storeAnswering([
            $this->typesenseHit(0.248, [
                'id' => 'agent_document:01a0fafa:1293:0',
                'content' => 'Shepard Auto Group available Lots by Tags: CDJR -> 178 New County Rd',
                'sourceType' => 'agent_document',
                'sourceName' => '01a0fafa-2c6b-7099-b3bc-6280c3cfcc5a',
                'apps_id' => 2,
                'companies_id' => 12038,
                'entity_type' => 'Kanvas\\Intelligence\\Agents\\Models\\Agent',
                'entity_id' => 1293,
                'source_type' => 'agent_document',
                'created_at' => 1_759_000_000,
            ]),
            $this->typesenseHit(0.300, [
                'id' => 'lead-803668-message-928536-0',
                'content' => 'whats your address?',
                'sourceType' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                'sourceName' => 'Kanvas\\Guild\\Leads\\Models\\Lead:2:12038:803668',
                'apps_id' => 2,
                'companies_id' => 12038,
                'entity_type' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                'entity_id' => 803668,
                'source_type' => 'message',
                'created_at' => 1_759_800_000,
            ]),
        ]);

        $hits = $store->search([0.1, 0.2, 0.3], KnowledgeScope::forTenant(2, 12038), 8);
        $this->assertArrayHasKey('content', $hits[0]['metadata'], 'The store hands the whole row along; that is the input the ranking has to cope with');

        $ranked = KnowledgeRetrieval::rank($hits, [], 8, 'whats your address?');

        $this->assertCount(1, $ranked, 'The question itself is dropped');
        $this->assertSame('[Company document] Shepard Auto Group available Lots by Tags: CDJR -> 178 New County Rd', $ranked[0]->getContent());
        $this->assertSame(1759000000, $ranked[0]->getMetadata()['created_at']);
        $this->assertArrayNotHasKey('content', $ranked[0]->getMetadata());

        $tool = new SearchKnowledgeTool($this->retrievalReturning($ranked));
        $result = $tool->__invoke(query: 'CDJR lot address');

        $this->assertSame('company_document', $result['results'][0]['source']);
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private function storeAnswering(array $hits): TypesenseKnowledgeStore
    {
        $multiSearch = Mockery::mock(MultiSearch::class);
        $multiSearch->shouldReceive('perform')->once()->andReturn(['results' => [['hits' => $hits]]]);

        $client = Mockery::mock(Client::class);
        $client->multiSearch = $multiSearch;

        return new TypesenseKnowledgeStore($client, 'knowledge-test', 3);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function typesenseHit(float $vectorDistance, array $document): array
    {
        return ['document' => $document, 'vector_distance' => $vectorDistance];
    }

    /**
     * @param list<Document> $documents
     */
    private function retrievalReturning(array $documents): RetrievalInterface
    {
        return new class ($documents) implements RetrievalInterface {
            /** @param list<Document> $documents */
            public function __construct(private readonly array $documents)
            {
            }

            public function retrieve(Message $query, ?FilterExpression $filters = null): array
            {
                return $this->documents;
            }
        };
    }
}
