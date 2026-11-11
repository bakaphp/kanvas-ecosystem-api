<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Intelligence\Knowledge\Jobs\IndexCompanyKnowledgeUrlJob;
use Kanvas\Intelligence\Knowledge\Jobs\PruneCompanyKnowledgeJob;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ReadsOnboardingGraphQL;

/**
 * Serial: writes a company setting, which is shared Redis state across paratest processes.
 */
#[Group('serial')]
final class CompanyKnowledgeUrlsTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private const string ADD = 'mutation($url: String!) { addCompanyKnowledgeUrl(url: $url) { url status chunks error } }';
    private const string LIST = 'query { companyKnowledgeUrls { url status } }';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->currentCompany()->del(CompanyKnowledgeService::URLS_SETTING);

        parent::tearDown();
    }

    public function testAddingAUrlRecordsItPendingAndQueuesIndexing(): void
    {
        $added = $this->graphQLData($this->graphQL(self::ADD, ['url' => 'example.com/catalog']), 'addCompanyKnowledgeUrl');

        $this->assertSame('https://example.com/catalog', $added['url']);
        $this->assertSame('PENDING', $added['status']);

        $listed = $this->graphQLData($this->graphQL(self::LIST), 'companyKnowledgeUrls');
        $this->assertSame(['https://example.com/catalog'], array_column($listed, 'url'));

        Queue::assertPushed(IndexCompanyKnowledgeUrlJob::class, fn (IndexCompanyKnowledgeUrlJob $job): bool => $job->queue === 'onboarding'
            && $job->url === 'https://example.com/catalog'
            && $job->company->getId() === $this->currentCompany()->getId());
    }

    public function testAPrivateUrlIsRefused(): void
    {
        $response = $this->graphQL(self::ADD, ['url' => 'http://10.0.0.8/admin']);

        $this->assertNull($response->json('data.addCompanyKnowledgeUrl'));
        $this->assertNotEmpty($response->json('errors'));
        Queue::assertNotPushed(IndexCompanyKnowledgeUrlJob::class);
    }

    public function testRemovingAUrlDropsItAndQueuesThePrune(): void
    {
        $this->graphQL(self::ADD, ['url' => 'https://example.com/pricing'])->assertSuccessful();

        $response = $this->graphQL('mutation($url: String!) { removeCompanyKnowledgeUrl(url: $url) }', ['url' => 'https://example.com/pricing']);
        $this->assertTrue($response->json('data.removeCompanyKnowledgeUrl'), json_encode($response->json()));

        $this->assertSame([], $this->graphQLData($this->graphQL(self::LIST), 'companyKnowledgeUrls'));
        Queue::assertPushed(PruneCompanyKnowledgeJob::class, fn (PruneCompanyKnowledgeJob $job): bool => $job->sourceType === CompanyKnowledgeService::SOURCE_TYPE_URL
            && $job->sourceName === 'https://example.com/pricing');
    }
}
