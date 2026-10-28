<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Tests\TestCase;
use Tests\Traits\ReadsOnboardingGraphQL;

final class UploadCompanyKnowledgeMutationTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem'];

    public function testUploadAttachesToTheOnboardingRowNotTheCompany(): void
    {
        Queue::fake();
        $company = auth()->user()->getCurrentCompany();
        $companyFilesBefore = $company->files()->count();

        $response = $this->multipartGraphQL(
            [
                'query' => /** @lang GraphQL */ '
                    mutation($files: [Upload!]!) {
                        uploadCompanyKnowledge(files: $files) { id status knowledge_count knowledge_documents { uuid name } }
                    }
                ',
                'variables' => ['files' => [null]],
            ],
            ['0' => ['variables.files.0']],
            ['0' => UploadedFile::fake()->createWithContent('faq.txt', "Where is the warehouse?\nAt 5 Dock Road.")],
        );

        $data = $response->json('data.uploadCompanyKnowledge');
        $this->assertNotNull($data, json_encode($response->json()));

        $row = CompanyOnboarding::forCompany(app(Apps::class), $company);
        $this->assertSame((string) $row->getId(), (string) $data['id']);
        $this->assertSame(1, $row->files()->count());
        $this->assertSame('faq.txt', $row->files()->first()->name);
        $this->assertSame($companyFilesBefore, $company->files()->count());
        $this->assertSame(['faq.txt'], array_column($data['knowledge_documents'], 'name'));
    }

    public function testADeletedDocumentLeavesTheKnowledgeList(): void
    {
        Queue::fake();
        $uuid = $this->upload('policy.txt')['knowledge_documents'][0]['uuid'];

        $this->graphQL('mutation($uuid: String!) { deleteFile(uuid: $uuid) }', ['uuid' => $uuid])->assertSuccessful();

        $names = array_column($this->graphQLData($this->graphQL('query { companyOnboarding { knowledge_documents { name } } }'), 'companyOnboarding')['knowledge_documents'], 'name');
        $this->assertNotContains('policy.txt', $names);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $name): array
    {
        $response = $this->multipartGraphQL(
            [
                'query' => /** @lang GraphQL */ '
                    mutation($files: [Upload!]!) {
                        uploadCompanyKnowledge(files: $files) { knowledge_documents { uuid name } }
                    }
                ',
                'variables' => ['files' => [null]],
            ],
            ['0' => ['variables.files.0']],
            ['0' => UploadedFile::fake()->createWithContent($name, 'Return policy: 30 days.')],
        );

        return $this->graphQLData($response, 'uploadCompanyKnowledge');
    }
}
