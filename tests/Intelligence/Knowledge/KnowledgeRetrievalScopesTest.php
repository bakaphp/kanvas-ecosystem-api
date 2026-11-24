<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use Kanvas\Intelligence\Knowledge\Research\Actions\ResearchCompanyWebsiteAction;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use Mockery;
use Tests\TestCase;
use Typesense\Client;
use Typesense\MultiSearch;

/**
 * An agent's knowledge base is its own documents plus the company's (D7). Polly (agent 1292, Shepard
 * Auto) searched the whole company: eight lead rows echoing "Shepard Select lot" outscored its lot
 * guide (0.747), and agent 1290's email templates came back as if they were Polly's (2026-11-05).
 */
final class KnowledgeRetrievalScopesTest extends TestCase
{
    private const string LOT_GUIDE = 'Shepard Auto Group available Lots by Tags: Select -> Shepard Select Used Cars 165 New County Rd, Thomaston';

    private const string HOURS_PAGE = 'Showroom hours: Monday to Saturday 9am to 7pm';

    /** @var list<string> */
    private array $searches = [];

    public function testAnInternalAgentSearchesItsOwnAndTheCompanysDocumentsOnly(): void
    {
        [$app, $company, $agent] = $this->tenant();

        $ranked = new KnowledgeRetrieval($app, $company, $agent)->retrieveFrom(
            $this->store($agent),
            [0.1, 0.2, 0.3],
            'Shepard Select lot address',
        );

        $this->assertCount(2, $this->searches, 'No company-wide search and no record search');
        $this->assertStringContainsString('entity_id:=1292', $this->searches[0]);
        $this->assertStringContainsString('entity_id:=0', $this->searches[1]);
        foreach ($this->searches as $filter) {
            $this->assertStringContainsString('sourceType:=[`agent_document`', $filter, 'Documents only, never record rows or memory');
        }

        $this->assertSame(
            ['[Company document] ' . self::LOT_GUIDE, '[Company document] ' . self::HOURS_PAGE],
            array_map(fn ($document): string => $document->getContent(), $ranked),
            'A company page is labelled as a document, not as record history'
        );
    }

    public function testACustomerFacingAgentGetsItsLeadsRowsAfterTheDocuments(): void
    {
        [$app, $company, $agent] = $this->tenant();
        $lead = new Lead()->forceFill([
            'id' => 828091,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        $ranked = new KnowledgeRetrieval(
            $app,
            $company,
            $agent,
            $lead,
        )->retrieveFrom(
            $this->store($agent),
            [0.1, 0.2, 0.3],
            'Shepard Select lot address',
        );

        $this->assertCount(3, $this->searches);
        $this->assertStringContainsString('entity_id:=828091', $this->searches[2]);
        $this->assertStringNotContainsString('sourceType:=[', $this->searches[2], 'The record search reads its rows, not documents');
        $this->assertSame('[Company document] ' . self::LOT_GUIDE, $ranked[0]->getContent());
        $this->assertSame('[Company document] ' . self::HOURS_PAGE, $ranked[1]->getContent());
        $this->assertStringStartsWith('[Record history', $ranked[2]->getContent(), 'Lead rows outscore the guide and still come after it');
    }

    public function testEveryDocumentKindReadsAsADocument(): void
    {
        foreach ([CompanyKnowledgeService::SOURCE_TYPE_DOCUMENT, CompanyKnowledgeService::SOURCE_TYPE_URL, ResearchCompanyWebsiteAction::SOURCE_TYPE] as $kind) {
            $ranked = KnowledgeRetrieval::rank(
                [['content' => 'Lot list', 'sourceType' => $kind, 'sourceName' => 'x', 'score' => 0.7, 'metadata' => []]],
                [],
                8,
                'where is the lot',
            );

            $this->assertSame('[Company document] Lot list', $ranked[0]->getContent(), $kind);
        }
    }

    /**
     * @return array{Apps, Companies, Agent}
     */
    private function tenant(): array
    {
        $app = app(Apps::class);
        $company = Companies::first();

        return [$app, $company, new Agent()->forceFill([
            'id' => 1292,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ])];
    }

    private function store(Agent $agent): TypesenseKnowledgeStore
    {
        $multiSearch = Mockery::mock(MultiSearch::class);
        $multiSearch->shouldReceive('perform')->andReturnUsing(function (array $request) use ($agent): array {
            $filter = $request['searches'][0]['filter_by'];
            $this->searches[] = $filter;

            $hits = match (true) {
                str_contains($filter, 'entity_id:=' . $agent->getKey()) => [$this->hit(0.253, self::LOT_GUIDE, 'agent_document')],
                str_contains($filter, 'entity_id:=0') => [$this->hit(0.4, self::HOURS_PAGE, CompanyKnowledgeService::SOURCE_TYPE_URL)],
                default => [
                    $this->hit(0.155, 'you know the address for Shepard select', Lead::class),
                    $this->hit(0.164, 'Shepard Select lot', Lead::class),
                ],
            };

            return ['results' => [['hits' => $hits]]];
        });
        $client = Mockery::mock(Client::class);
        $client->multiSearch = $multiSearch;

        return new TypesenseKnowledgeStore($client, 'knowledge-test', 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function hit(float $vectorDistance, string $content, string $sourceType): array
    {
        return [
            'document' => [
                'content' => $content,
                'sourceType' => $sourceType,
                'sourceName' => 'test',
                'created_at' => 1_762_300_000,
            ],
            'vector_distance' => $vectorDistance,
        ];
    }
}
