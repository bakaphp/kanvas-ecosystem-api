<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Baka\Search\SearchEngineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Workflows\IndexKnowledgeDocumentActivity;
use Laravel\Ai\Embeddings;
use Tests\TestCase;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;

/**
 * The regression test for the cross-company leak: two companies in ONE app share
 * one collection, and a company-scoped search must never return the other
 * company's rows, nor any entity-scoped (entity_id>0) rows. Runs against a live
 * Typesense cluster (host `typesense`), mirroring the prior round-trip tests.
 */
class KnowledgeScopeIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const TEST_COLLECTION = 'knowledge_scope_isolation_test';
    private const COMPANY_A = 901001;
    private const COMPANY_B = 902002;

    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(KnowledgeConfigurationEnum::COLLECTION->value, self::TEST_COLLECTION);
        $this->kanvasApp->set(KnowledgeConfigurationEnum::VECTOR_DIMENSION->value, 4);

        // Constant fake vector — scope isolation is proved by the filter, not similarity.
        Embeddings::fake(fn (): array => [[0.1, 0.2, 0.3, 0.4]]);
    }

    protected function tearDown(): void
    {
        try {
            SearchEngineResolver::getTypesenseClient($this->kanvasApp->get('typesense_search_settings') ?? [])
                ->collections[self::TEST_COLLECTION]->delete();
        } catch (Throwable) {
            // collection may not exist if the test bailed early
        }

        $this->kanvasApp->del(KnowledgeConfigurationEnum::COLLECTION->value);
        $this->kanvasApp->del(KnowledgeConfigurationEnum::VECTOR_DIMENSION->value);
        $this->kanvasApp->del(KnowledgeConfigurationEnum::MIN_SCORE->value);

        parent::tearDown();
    }

    public function testMinScoreConfigIsNullUnlessSet(): void
    {
        $this->kanvasApp->del(KnowledgeConfigurationEnum::MIN_SCORE->value);
        $this->assertNull(KnowledgeComponents::minScore($this->kanvasApp));

        $this->kanvasApp->set(KnowledgeConfigurationEnum::MIN_SCORE->value, 0.42);
        $this->assertSame(0.42, KnowledgeComponents::minScore($this->kanvasApp));
    }

    public function testMinScoreFloorDropsWeakMatches(): void
    {
        $this->requireTypesense();

        $appId = $this->kanvasApp->getId();
        $scope = new KnowledgeScope($appId, self::COMPANY_A);
        KnowledgeComponents::indexer($this->kanvasApp)->indexDocument(
            $scope,
            'agent_document',
            'company-a-policy',
            'Alpha refund policy: refunds within 30 days.',
        );

        $vector = KnowledgeComponents::embedder($this->kanvasApp)->embed('policy');
        $store = KnowledgeComponents::store($this->kanvasApp);

        $this->assertNotEmpty($store->search($vector, $scope, 10));
        // An unreachable floor (score maxes at 1.0) drops every hit.
        $this->assertEmpty($store->search($vector, $scope, 10, 2.0));
    }

    public function testCompanyScopedSearchNeverReturnsAnotherCompanysKnowledge(): void
    {
        $this->requireTypesense();

        $appId = $this->kanvasApp->getId();
        $indexer = KnowledgeComponents::indexer($this->kanvasApp);

        $indexer->indexDocument(
            new KnowledgeScope($appId, self::COMPANY_A),
            'agent_document',
            'company-a-policy',
            'Alpha refund policy: refunds within 30 days.',
        );
        $indexer->indexDocument(
            new KnowledgeScope($appId, self::COMPANY_B),
            'agent_document',
            'company-b-policy',
            'Beta shipping policy: ships in 48 hours.',
        );

        // An entity-scoped row (a Lead's private note) in company A must not surface
        // in company A's tenant (entity_id=0) knowledge search either.
        KnowledgeComponents::store($this->kanvasApp)->upsert([
            new KnowledgeDocument(
                id: 'company-a-lead-note',
                content: 'Alpha lead private note: prospect wants a discount.',
                metadata: [
                    'apps_id' => $appId,
                    'companies_id' => self::COMPANY_A,
                    'entity_type' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                    'entity_id' => 55,
                    'source_type' => 'lead',
                    'sourceType' => 'lead',
                    'sourceName' => 'lead:55',
                    'embedding' => [0.1, 0.2, 0.3, 0.4],
                ],
            ),
        ]);

        $vector = KnowledgeComponents::embedder($this->kanvasApp)->embed('policy');
        $store = KnowledgeComponents::store($this->kanvasApp);

        $companyA = collect($store->search($vector, new KnowledgeScope($appId, self::COMPANY_A), 10))
            ->pluck('content');
        $companyB = collect($store->search($vector, new KnowledgeScope($appId, self::COMPANY_B), 10))
            ->pluck('content');

        $this->assertTrue($companyA->contains(fn (string $c): bool => str_contains($c, 'Alpha refund')));
        $this->assertFalse($companyA->contains(fn (string $c): bool => str_contains($c, 'Beta shipping')));
        $this->assertFalse($companyA->contains(fn (string $c): bool => str_contains($c, 'Alpha lead private')));

        $this->assertTrue($companyB->contains(fn (string $c): bool => str_contains($c, 'Beta shipping')));
        $this->assertFalse($companyB->contains(fn (string $c): bool => str_contains($c, 'Alpha refund')));
    }

    public function testOrganizationScopedSearchIncludesAllCompanyEntitiesButNeverAnotherCompany(): void
    {
        $this->requireTypesense();

        $appId = $this->kanvasApp->getId();
        $store = KnowledgeComponents::store($this->kanvasApp);
        $records = [];

        foreach ([
            ['id' => 'company-a-lead-55', 'company' => self::COMPANY_A, 'entity' => 55, 'content' => 'Alpha lead wants a sedan.'],
            ['id' => 'company-a-lead-56', 'company' => self::COMPANY_A, 'entity' => 56, 'content' => 'Alpha lead wants an SUV.'],
            ['id' => 'company-b-lead-57', 'company' => self::COMPANY_B, 'entity' => 57, 'content' => 'Beta lead wants a truck.'],
        ] as $row) {
            $records[] = new KnowledgeDocument(
                id: $row['id'],
                content: $row['content'],
                metadata: [
                    'apps_id' => $appId,
                    'companies_id' => $row['company'],
                    'entity_type' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                    'entity_id' => $row['entity'],
                    'source_type' => 'lead',
                    'sourceType' => 'lead',
                    'sourceName' => 'lead:' . $row['entity'],
                    'embedding' => [0.1, 0.2, 0.3, 0.4],
                ],
            );
        }

        $store->upsert($records);
        $vector = KnowledgeComponents::embedder($this->kanvasApp)->embed('vehicle interest');
        $results = collect($store->search(
            $vector,
            KnowledgeScope::forOrganization($appId, self::COMPANY_A),
            10,
        ))->pluck('content');

        $this->assertTrue($results->contains('Alpha lead wants a sedan.'));
        $this->assertTrue($results->contains('Alpha lead wants an SUV.'));
        $this->assertFalse($results->contains('Beta lead wants a truck.'));
    }

    public function testAgentScopedSearchNeverReturnsAnotherAgentsKnowledge(): void
    {
        $this->requireTypesense();

        $appId = $this->kanvasApp->getId();
        $agentType = 'Kanvas\\Intelligence\\Agents\\Models\\Agent';
        $scopeAgentA = new KnowledgeScope($appId, self::COMPANY_A, $agentType, 7001);
        $scopeAgentB = new KnowledgeScope($appId, self::COMPANY_A, $agentType, 7002);

        $indexer = KnowledgeComponents::indexer($this->kanvasApp);
        $indexer->indexDocument(
            $scopeAgentA,
            IndexKnowledgeDocumentActivity::SOURCE_TYPE,
            'agent-a-doc',
            'Agent A handbook: onboarding steps for the sales team.',
        );
        $indexer->indexDocument(
            $scopeAgentB,
            IndexKnowledgeDocumentActivity::SOURCE_TYPE,
            'agent-b-doc',
            'Agent B handbook: escalation matrix for support.',
        );

        $vector = KnowledgeComponents::embedder($this->kanvasApp)->embed('handbook');
        $store = KnowledgeComponents::store($this->kanvasApp);

        $agentA = collect($store->search($vector, $scopeAgentA, 10))->pluck('content');
        $agentB = collect($store->search($vector, $scopeAgentB, 10))->pluck('content');

        $this->assertTrue($agentA->contains(fn (string $c): bool => str_contains($c, 'Agent A handbook')));
        $this->assertFalse($agentA->contains(fn (string $c): bool => str_contains($c, 'Agent B handbook')));

        $this->assertTrue($agentB->contains(fn (string $c): bool => str_contains($c, 'Agent B handbook')));
        $this->assertFalse($agentB->contains(fn (string $c): bool => str_contains($c, 'Agent A handbook')));
    }

    public function testDeleteBySourceRemovesADeletedDocumentsChunks(): void
    {
        $this->requireTypesense();

        $appId = $this->kanvasApp->getId();
        $scope = new KnowledgeScope($appId, self::COMPANY_A);
        KnowledgeComponents::indexer($this->kanvasApp)->indexDocument(
            $scope,
            IndexKnowledgeDocumentActivity::SOURCE_TYPE,
            'file-to-delete',
            'Gamma handbook: this document is about to be deleted.',
        );

        $vector = KnowledgeComponents::embedder($this->kanvasApp)->embed('handbook');
        $store = KnowledgeComponents::store($this->kanvasApp);
        $present = collect($store->search($vector, $scope, 10))->pluck('content');
        $this->assertTrue($present->contains(fn (string $c): bool => str_contains($c, 'about to be deleted')));

        // What PruneKnowledgeDocumentActivity does on a Filesystem delete.
        $store->deleteBySource($scope, IndexKnowledgeDocumentActivity::SOURCE_TYPE, 'file-to-delete');

        $afterPrune = collect($store->search($vector, $scope, 10))->pluck('content');
        $this->assertFalse($afterPrune->contains(fn (string $c): bool => str_contains($c, 'about to be deleted')));
    }

    private function requireTypesense(): void
    {
        try {
            SearchEngineResolver::getTypesenseClient($this->kanvasApp->get('typesense_search_settings') ?? [])
                ->collections[self::TEST_COLLECTION]->retrieve();
        } catch (ObjectNotFound) {
            // reachable, collection just doesn't exist yet — good to proceed
        } catch (Throwable $e) {
            $this->markTestSkipped('Typesense cluster not reachable: ' . $e->getMessage());
        }
    }
}
