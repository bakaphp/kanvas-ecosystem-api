<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Intelligence\Knowledge\Jobs\IndexCompanyKnowledgeFilesJob;
use Kanvas\Intelligence\Knowledge\Jobs\PruneCompanyKnowledgeJob;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use Tests\TestCase;
use Tests\Traits\MakesFilesystemRows;
use Tests\Traits\ReadsOnboardingGraphQL;

final class CompanyKnowledgeDocumentsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesFilesystemRows;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private const string LIST = 'query { companyKnowledgeDocuments { uuid name } }';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function testUploadAttachesUnderTheMarkerAndQueuesIndexing(): void
    {
        $company = $this->currentCompany();
        $before = $company->files()->count();

        $uploaded = $this->upload('faq.txt');

        $this->assertSame(['faq.txt'], array_column($uploaded, 'name'));
        $this->assertSame($before + 1, $company->files()->count());

        $file = Filesystem::query()->where('uuid', $uploaded[0]['uuid'])->firstOrFail();
        $entity = FilesystemEntities::query()->where('filesystem_id', $file->getId())->firstOrFail();
        $this->assertSame(CompanyKnowledgeService::FIELD_PREFIX . $file->getId(), $entity->field_name);

        Queue::assertPushed(IndexCompanyKnowledgeFilesJob::class, fn (IndexCompanyKnowledgeFilesJob $job): bool => $job->queue === 'onboarding'
            && $job->company->getId() === $company->getId()
            && $job->filesystemIds === [$file->getId()]);
    }

    public function testOnlyKnowledgeFilesAreListed(): void
    {
        $company = $this->currentCompany();
        $company->disableWorkflows();
        $logo = $this->makeFilesystemRow($company->getId(), 'logo.png');
        new AttachFilesystemAction($logo, $company)->execute('logo.png');

        $this->upload('policy.txt');

        $listed = array_column($this->graphQLData($this->graphQL(self::LIST), 'companyKnowledgeDocuments'), 'name');
        $this->assertContains('policy.txt', $listed);
        $this->assertNotContains('logo.png', $listed);

        $onboarding = $this->graphQLData($this->graphQL('query { companyOnboarding { knowledge_documents { name } } }'), 'companyOnboarding');
        $this->assertContains('policy.txt', array_column($onboarding['knowledge_documents'], 'name'));
    }

    public function testDeleteRemovesTheDocumentAndQueuesThePrune(): void
    {
        $uuid = $this->upload('returns.txt')[0]['uuid'];

        $response = $this->graphQL('mutation($uuid: String!) { deleteCompanyKnowledgeDocument(uuid: $uuid) }', ['uuid' => $uuid]);
        $this->assertTrue($response->json('data.deleteCompanyKnowledgeDocument'), json_encode($response->json()));

        $listed = array_column($this->graphQLData($this->graphQL(self::LIST), 'companyKnowledgeDocuments'), 'name');
        $this->assertNotContains('returns.txt', $listed);

        Queue::assertPushed(PruneCompanyKnowledgeJob::class, fn (PruneCompanyKnowledgeJob $job): bool => $job->sourceType === CompanyKnowledgeService::SOURCE_TYPE_DOCUMENT
            && $job->sourceName === $uuid);
    }

    public function testDeleteRefusesAFileThatIsNotCompanyKnowledge(): void
    {
        $company = $this->currentCompany();
        $company->disableWorkflows();
        $logo = $this->makeFilesystemRow($company->getId(), 'logo.png');
        new AttachFilesystemAction($logo, $company)->execute('logo.png');

        $response = $this->graphQL('mutation($uuid: String!) { deleteCompanyKnowledgeDocument(uuid: $uuid) }', ['uuid' => $logo->uuid]);

        $this->assertNull($response->json('data.deleteCompanyKnowledgeDocument'));
        $this->assertNotEmpty($response->json('errors'));
        $this->assertFalse((bool) $logo->refresh()->is_deleted);
        Queue::assertNotPushed(PruneCompanyKnowledgeJob::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upload(string $name): array
    {
        $response = $this->multipartGraphQL(
            [
                'query' => /** @lang GraphQL */ '
                    mutation($files: [Upload!]!) {
                        uploadCompanyKnowledge(files: $files) { uuid name }
                    }
                ',
                'variables' => ['files' => [null]],
            ],
            ['0' => ['variables.files.0']],
            ['0' => UploadedFile::fake()->createWithContent($name, "Return policy: 30 days.\nWarehouse: 5 Dock Road.")],
        );

        return $this->graphQLData($response, 'uploadCompanyKnowledge');
    }
}
