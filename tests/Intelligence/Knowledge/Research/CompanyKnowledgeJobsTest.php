<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge\Research;

use Baka\Http\SafeUrlFetcher;
use Baka\Search\SearchEngineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Jobs\IndexCompanyKnowledgeFilesJob;
use Kanvas\Intelligence\Knowledge\Jobs\IndexCompanyKnowledgeUrlJob;
use Kanvas\Intelligence\Knowledge\Jobs\PruneCompanyKnowledgeJob;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Workflows\IndexKnowledgeDocumentActivity;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Laravel\Ai\Embeddings;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;
use Tests\Traits\MakesFilesystemRows;
use Tests\Traits\ManagesAppSettings;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Serial: app settings. Runs against the live Typesense cluster in a throwaway collection.
 */
#[Group('serial')]
final class CompanyKnowledgeJobsTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use MakesFilesystemRows;
    use ManagesAppSettings {
        tearDown as restoreAppSettings;
    }

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->company->disableWorkflows();
        $this->collection = 'knowledge_company_jobs_test_' . uniqid();
        $this->rememberAppSettings(
            KnowledgeConfigurationEnum::COLLECTION->value,
            KnowledgeConfigurationEnum::VECTOR_DIMENSION->value,
            KnowledgeConfigurationEnum::ENABLED->value,
        );
        $this->kanvasApp->set(KnowledgeConfigurationEnum::COLLECTION->value, $this->collection);
        $this->kanvasApp->set(KnowledgeConfigurationEnum::VECTOR_DIMENSION->value, 4);
        $this->kanvasApp->set(KnowledgeConfigurationEnum::ENABLED->value, 1);
        Embeddings::fake(fn (): array => [[0.1, 0.2, 0.3, 0.4]]);
        SafeUrlFetcher::fake(static fn (string $url): string => "<html><title>Catalog</title><body>Where is the warehouse?\nThe warehouse is at 5 Dock Road, Haina.</body></html>");
    }

    protected function tearDown(): void
    {
        try {
            SearchEngineResolver::typesenseClient($this->kanvasApp)->collections[$this->collection]->delete();
        } catch (Throwable) {
        }
        $this->company->del(CompanyKnowledgeService::URLS_SETTING);

        $this->restoreAppSettings();
    }

    public function testFilesJobIndexesOnlyMarkerFilesCompanyWide(): void
    {
        $this->requireTypesense();
        $knowledge = $this->attach('warehouse.txt');
        $contract = $this->attach('contract.txt', knowledge: false);

        new IndexCompanyKnowledgeFilesJob(
            $this->kanvasApp,
            $this->company,
            $this->user,
            [$knowledge->getId(), $contract->getId()],
        )->handle();

        $hits = $this->search('warehouse');
        $this->assertCount(1, $hits);
        $this->assertSame(CompanyKnowledgeService::SOURCE_TYPE_DOCUMENT, $hits[0]['sourceType']);
        $this->assertSame($knowledge->uuid, $hits[0]['sourceName']);
    }

    public function testUrlJobIndexesThePageAndRecordsTheOutcome(): void
    {
        $this->requireTypesense();
        $url = 'https://93.184.216.34/catalog';
        new CompanyKnowledgeService()->recordUrl($this->company, $url, ['status' => 'pending']);

        new IndexCompanyKnowledgeUrlJob(
            $this->kanvasApp,
            $this->company,
            $this->user,
            $url,
        )->handle();

        $hits = $this->search('warehouse');
        $this->assertNotEmpty($hits);
        $this->assertSame(CompanyKnowledgeService::SOURCE_TYPE_URL, $hits[0]['sourceType']);
        $this->assertSame($url, $hits[0]['sourceName']);

        $recorded = new CompanyKnowledgeService()->urls($this->company)[0];
        $this->assertSame('indexed', $recorded['status']);
        $this->assertGreaterThan(0, $recorded['chunks']);
    }

    public function testPruneJobRemovesASourcesChunks(): void
    {
        $this->requireTypesense();
        $knowledge = $this->attach('warehouse.txt');
        new IndexCompanyKnowledgeFilesJob(
            $this->kanvasApp,
            $this->company,
            $this->user,
            [$knowledge->getId()],
        )->handle();
        $this->assertNotEmpty($this->search('warehouse'));

        new PruneCompanyKnowledgeJob(
            $this->kanvasApp,
            $this->company,
            CompanyKnowledgeService::SOURCE_TYPE_DOCUMENT,
            $knowledge->uuid,
        )->handle();

        $this->assertSame([], $this->search('warehouse'));
    }

    public function testAgentActivityStillIndexesPerAgent(): void
    {
        $this->requireTypesense();
        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::INTERNAL,
            InternalHandler::class,
            $this->company,
            $this->user,
        );
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['user_id' => Users::factory()->create()->getId()]);
        $agent->disableWorkflows();
        $file = $this->makeFilesystemRow($this->company->getId(), 'handbook.txt');
        new AttachFilesystemAction($file, $agent)->execute('handbook.txt');
        $agent->unsetRelation('files');

        $result = new IndexKnowledgeDocumentActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])
            ->execute($agent, $this->kanvasApp, []);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
    }

    private function attach(string $name, bool $knowledge = true)
    {
        $file = $this->makeFilesystemRow($this->company->getId(), $name);
        new AttachFilesystemAction($file, $this->company)->execute(
            $knowledge ? CompanyKnowledgeService::FIELD_PREFIX . $file->getId() : $name,
        );
        $this->company->unsetRelation('files');

        return $file;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $question): array
    {
        return KnowledgeComponents::store($this->kanvasApp)->search(
            KnowledgeComponents::embedder($this->kanvasApp)->embed($question),
            KnowledgeScope::forTenant($this->kanvasApp->getId(), $this->company->getId()),
            10,
        );
    }

    private function requireTypesense(): void
    {
        try {
            SearchEngineResolver::typesenseClient($this->kanvasApp)->collections[$this->collection]->retrieve();
        } catch (ObjectNotFound) {
        } catch (Throwable $e) {
            $this->markTestSkipped('Typesense cluster not reachable: ' . $e->getMessage());
        }
    }
}
