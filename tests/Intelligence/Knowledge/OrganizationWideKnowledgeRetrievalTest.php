<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use Mockery;
use Tests\TestCase;
use Typesense\Client;
use Typesense\MultiSearch;

/**
 * Polly (agent 1292, Shepard Auto) searches the whole company. Its lot guide scored 0.747 on "Shepard
 * Select lot address" while eight lead rows echoing the question scored 0.79-0.85, so the single
 * company-wide search never returned the guide and the agent told staff it had no address (2026-11-05).
 */
final class OrganizationWideKnowledgeRetrievalTest extends TestCase
{
    public function testTheAgentsDocumentsComeFirstEvenWhenCompanyRowsOutscoreThem(): void
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $agent = new Agent()->forceFill([
            'id' => 1292,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        $lotGuide = 'Shepard Auto Group available Lots by Tags: Select -> Shepard Select Used Cars 165 New County Rd, Thomaston';
        $leadRows = [
            $this->typesenseHit(0.155, 'you know the address for Shepard select', 828091),
            $this->typesenseHit(0.164, 'Shepard Select lot', 828764),
            $this->typesenseHit(0.164, 'Shepard Select lot', 813976),
            $this->typesenseHit(0.204, "I don't have the street address for Shepard Select saved", 828091),
        ];

        $searches = [];
        $multiSearch = Mockery::mock(MultiSearch::class);
        $multiSearch->shouldReceive('perform')->twice()->andReturnUsing(
            function (array $request) use (&$searches, $agent, $lotGuide, $leadRows): array {
                $filter = $request['searches'][0]['filter_by'];
                $searches[] = $filter;

                return ['results' => [['hits' => str_contains($filter, 'entity_id:=' . $agent->getKey())
                    ? [$this->typesenseHit(0.253, $lotGuide, $agent->getKey(), 'agent_document')]
                    : $leadRows,
                ]]];
            }
        );
        $client = Mockery::mock(Client::class);
        $client->multiSearch = $multiSearch;

        $ranked = new KnowledgeRetrieval(
            $app,
            $company,
            $agent,
            organizationWide: true,
        )->retrieveFrom(
            new TypesenseKnowledgeStore($client, 'knowledge-test', 3),
            [0.1, 0.2, 0.3],
            'Shepard Select lot address',
        );

        $this->assertStringContainsString('entity_id:=1292', $searches[0], 'The agent\'s own documents get a search of their own');
        $this->assertStringNotContainsString('entity_id', $searches[1], 'The second search is still company-wide');
        $this->assertSame("[Company document] {$lotGuide}", $ranked[0]->getContent());
        $this->assertCount(4, $ranked, 'Company rows fill the rest, the repeated "Shepard Select lot" once');
    }

    /**
     * @return array<string, mixed>
     */
    private function typesenseHit(
        float $vectorDistance,
        string $content,
        int $entityId,
        string $sourceType = 'Kanvas\\Guild\\Leads\\Models\\Lead'
    ): array {
        return [
            'document' => [
                'content' => $content,
                'sourceType' => $sourceType,
                'sourceName' => 'test',
                'entity_id' => $entityId,
                'created_at' => 1_762_300_000,
            ],
            'vector_distance' => $vectorDistance,
        ];
    }
}
