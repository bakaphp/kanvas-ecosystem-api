<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge\Research;

use Baka\Http\SafeUrlFetcher;
use Baka\Search\SearchEngineResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Research\Workflows\IndexCompanyDocumentActivity;
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
final class IndexCompanyDocumentActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;
    use MakesFilesystemRows;
    use ManagesAppSettings {
        tearDown as restoreAppSettings;
    }

    protected $connectionsToTransact = [null, 'ecosystem', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->collection = 'knowledge_company_document_test_' . uniqid();
        $this->rememberAppSettings(
            KnowledgeConfigurationEnum::COLLECTION->value,
            KnowledgeConfigurationEnum::VECTOR_DIMENSION->value,
        );
        $this->kanvasApp->set(KnowledgeConfigurationEnum::COLLECTION->value, $this->collection);
        $this->kanvasApp->set(KnowledgeConfigurationEnum::VECTOR_DIMENSION->value, 4);
        Embeddings::fake(fn (): array => [[0.1, 0.2, 0.3, 0.4]]);
        SafeUrlFetcher::fake(static fn (string $url): string => "Where is the warehouse?\nThe warehouse is at 5 Dock Road, Haina.\n");
    }

    protected function tearDown(): void
    {
        try {
            SearchEngineResolver::typesenseClient($this->kanvasApp)->collections[$this->collection]->delete();
        } catch (Throwable) {
        }

        $this->restoreAppSettings();
    }

    public function testIndexesTextFilesAttachedToTheOnboardingRowCompanyWide(): void
    {
        $this->requireTypesense();
        $this->setUpInternalIntegration();
        $row = $this->row();
        $this->attach($row, 'warehouse.txt');
        $this->attach($row, 'logo.png');

        $result = new IndexCompanyDocumentActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])
            ->execute($row, $this->kanvasApp, []);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertStringContainsString('1 document', $result['message']);

        $hits = KnowledgeComponents::store($this->kanvasApp)->search(
            KnowledgeComponents::embedder($this->kanvasApp)->embed('warehouse'),
            KnowledgeScope::forTenant($this->kanvasApp->getId(), $this->company->getId()),
            10,
        );
        $this->assertNotEmpty($hits);
        $this->assertSame(IndexCompanyDocumentActivity::SOURCE_TYPE, $hits[0]['sourceType']);
        $this->assertStringContainsString('5 Dock Road', $hits[0]['content']);
    }

    public function testRefusesANonOnboardingEntity(): void
    {
        $agent = $this->agent();

        $result = new IndexCompanyDocumentActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])
            ->execute($agent, $this->kanvasApp, []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('CompanyOnboarding', $result['message']);
    }

    public function testFailsWhenNothingIndexableIsAttached(): void
    {
        $row = $this->row();
        $this->attach($row, 'logo.png');

        $result = new IndexCompanyDocumentActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])
            ->execute($row, $this->kanvasApp, []);

        $this->assertFalse($result['success']);
    }

    public function testAgentActivityStillIndexesAfterExtraction(): void
    {
        $this->requireTypesense();
        $this->setUpInternalIntegration();
        $agent = $this->agent();
        $this->attach($agent, 'handbook.txt');

        $result = new IndexKnowledgeDocumentActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])
            ->execute($agent, $this->kanvasApp, []);

        $this->assertTrue($result['success'] ?? false, json_encode($result));

        $hits = KnowledgeComponents::store($this->kanvasApp)->search(
            KnowledgeComponents::embedder($this->kanvasApp)->embed('warehouse'),
            KnowledgeScope::forModel($agent),
            10,
        );
        $this->assertNotEmpty($hits);
        $this->assertSame(IndexKnowledgeDocumentActivity::SOURCE_TYPE, $hits[0]['sourceType']);
    }

    private function row(): CompanyOnboarding
    {
        $row = new OnboardingStateService()->forCompany($this->kanvasApp, $this->company, auth()->user());
        $row->disableWorkflows();

        return $row;
    }

    /**
     * The agent's own user differs from the uploader, so the upload counts as a staff upload.
     */
    private function agent(): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['user_id' => Users::factory()->create()->getId()]);
        $agent->disableWorkflows();

        return $agent;
    }

    private function attach(Model $entity, string $name): void
    {
        $file = $this->makeFilesystemRow($this->company->getId(), $name);
        new AttachFilesystemAction($file, $entity)->execute($name);
        $entity->unsetRelation('files');
    }

    private function setUpInternalIntegration(): void
    {
        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::INTERNAL,
            InternalHandler::class,
            $this->company,
            auth()->user(),
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
