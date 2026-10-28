<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Tests\TestCase;

final class UploadOnboardingKnowledgeMutationTest extends TestCase
{
    use DatabaseTransactions;

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
                        uploadOnboardingKnowledge(files: $files) { id status knowledge_count }
                    }
                ',
                'variables' => ['files' => [null]],
            ],
            ['0' => ['variables.files.0']],
            ['0' => UploadedFile::fake()->createWithContent('faq.txt', "Where is the warehouse?\nAt 5 Dock Road.")],
        );

        $data = $response->json('data.uploadOnboardingKnowledge');
        $this->assertNotNull($data, json_encode($response->json()));

        $row = CompanyOnboarding::forCompany(app(Apps::class), $company);
        $this->assertSame((string) $row->getId(), (string) $data['id']);
        $this->assertSame(1, $row->files()->count());
        $this->assertSame('faq.txt', $row->files()->first()->name);
        $this->assertSame($companyFilesBefore, $company->files()->count());
    }
}
