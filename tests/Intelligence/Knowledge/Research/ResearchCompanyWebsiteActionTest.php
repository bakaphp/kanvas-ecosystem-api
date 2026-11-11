<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge\Research;

use Baka\Search\SearchEngineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Enums\ResearchStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum as TavilyConfigurationEnum;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Research\Actions\ResearchCompanyWebsiteAction;
use Kanvas\Intelligence\Knowledge\Research\Agents\CompanyResearchAgent;
use Kanvas\Intelligence\Knowledge\Research\DataTransferObject\CollectedPages;
use Kanvas\Intelligence\Knowledge\Research\Services\CompanyProfileService;
use Kanvas\Intelligence\Knowledge\Research\Services\WebsitePageCollector;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Serial: app settings (knowledge collection and flag, Tavily key) live in Redis. The knowledge
 * tests run against the live Typesense cluster in a throwaway collection, as KnowledgeScopeIsolationTest does.
 */
#[Group('serial')]
final class ResearchCompanyWebsiteActionTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings {
        tearDown as restoreAppSettings;
    }

    protected $connectionsToTransact = [null, 'ecosystem'];

    private const string HOME = 'https://93.184.216.34';

    private Apps $kanvasApp;
    private Companies $company;
    /** Unique per test: the store caches "collection exists" per process, and tearDown deletes it. */
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->collection = 'knowledge_company_research_test_' . uniqid();
        $this->company = Companies::factory()->create(['website' => self::HOME]);
        $this->rememberAppSettings(
            KnowledgeConfigurationEnum::COLLECTION->value,
            KnowledgeConfigurationEnum::VECTOR_DIMENSION->value,
            KnowledgeConfigurationEnum::ENABLED->value,
            TavilyConfigurationEnum::TAVILY_API_KEY->value,
        );
        $this->kanvasApp->set(KnowledgeConfigurationEnum::COLLECTION->value, $this->collection);
        $this->kanvasApp->set(KnowledgeConfigurationEnum::VECTOR_DIMENSION->value, 4);
        $this->kanvasApp->del(TavilyConfigurationEnum::TAVILY_API_KEY->value);
        Embeddings::fake(fn (): array => [[0.1, 0.2, 0.3, 0.4]]);

        new OnboardingStateService()->forCompany($this->kanvasApp, $this->company, auth()->user());
    }

    protected function tearDown(): void
    {
        try {
            SearchEngineResolver::typesenseClient($this->kanvasApp)->collections[$this->collection]->delete();
        } catch (Throwable) {
        }
        $this->company->del(CompanyProfileService::SETTING_KEY);

        $this->restoreAppSettings();
    }

    public function testWritesProfileAndIndexesCompanyWideWhenKnowledgeEnabled(): void
    {
        $this->requireTypesense();
        $this->kanvasApp->set(KnowledgeConfigurationEnum::ENABLED->value, 1);

        $result = $this->action($this->collector($this->pages()), $this->handler())->execute();

        $this->assertSame(ResearchStatusEnum::INDEXED, $result->status);
        $this->assertSame(2, $result->pagesIndexed);
        $this->assertGreaterThan(0, $result->knowledgeCount);
        $this->assertSame('Office furniture', $this->company->get(CompanyProfileService::SETTING_KEY)['industry']);

        $research = $this->row()->dataGet('research');
        $this->assertSame('indexed', $research['status']);
        $this->assertSame('tavily', $research['collector']);
        $this->assertSame(2, $research['pages_found']);
        $this->assertGreaterThan(0, $research['knowledge_count']);
        $this->assertSame([self::HOME, self::HOME . '/about'], $research['pages']);

        $hits = $this->search();
        $this->assertNotEmpty($hits);
        $this->assertSame(ResearchCompanyWebsiteAction::SOURCE_TYPE, $hits[0]['sourceType']);
        $this->assertTrue(collect($hits)->contains(fn (array $hit): bool => str_contains($hit['content'], 'Where is the showroom')));
    }

    public function testSkipsIndexingWhenKnowledgeDisabled(): void
    {
        $this->kanvasApp->set(KnowledgeConfigurationEnum::ENABLED->value, 0);
        CompanyResearchAgent::fake([$this->structured()]);

        $result = $this->action($this->collector($this->pages()))->execute();

        $this->assertSame(ResearchStatusEnum::INDEXED, $result->status);
        $this->assertSame(0, $result->pagesIndexed);
        $this->assertSame('Office furniture', $this->company->get(CompanyProfileService::SETTING_KEY)['industry']);
        $this->assertSame('knowledge_disabled', $this->row()->dataGet('research.note'));
        CompanyResearchAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'We make chairs.'));
    }

    public function testPublicMailDomainIsSkippedWithoutAnyCall(): void
    {
        $collector = Mockery::mock(WebsitePageCollector::class);
        $collector->shouldNotReceive('collect');
        $handler = Mockery::mock(CompanyResearchAgent::class);
        $handler->shouldNotReceive('promptWithConfig');

        $result = $this->action($collector, $handler, 'gmail.com')->execute();

        $this->assertSame(ResearchStatusEnum::SKIPPED, $result->status);
        $this->assertSame('public_email_domain', $result->error);
        $this->assertSame('skipped', $this->row()->dataGet('research.status'));
        $this->assertNull($this->company->get(CompanyProfileService::SETTING_KEY));
    }

    public function testPrivateHostFailsWithoutReport(): void
    {
        Http::fake();
        Exceptions::fake();
        $handler = Mockery::mock(CompanyResearchAgent::class);
        $handler->shouldNotReceive('promptWithConfig');

        $result = $this->action(null, $handler, 'http://127.0.0.1')->execute();

        $this->assertSame(ResearchStatusEnum::FAILED, $result->status);
        $this->assertNotNull($result->error);
        $this->assertSame('failed', $this->row()->dataGet('research.status'));
        $this->assertNotNull($this->row()->dataGet('research.error'));
        Http::assertNothingSent();
        Exceptions::assertNothingReported();
    }

    public function testPrunesPagesThatDisappeared(): void
    {
        $this->requireTypesense();
        $this->kanvasApp->set(KnowledgeConfigurationEnum::ENABLED->value, 1);

        $this->action($this->collector($this->pages()), $this->handler())->execute();
        $this->assertTrue(collect($this->search())->contains(fn (array $hit): bool => $hit['sourceName'] === self::HOME . '/about'));

        $this->action($this->collector(array_slice($this->pages(), 0, 1)), $this->handler(1))->execute();

        $hits = $this->search();
        $this->assertNotEmpty($hits);
        $this->assertFalse(collect($hits)->contains(fn (array $hit): bool => $hit['sourceName'] === self::HOME . '/about'));
        $this->assertSame([self::HOME], $this->row()->dataGet('research.pages'));
    }

    private function action(?WebsitePageCollector $collector, ?CompanyResearchAgent $handler = null, string $website = self::HOME): ResearchCompanyWebsiteAction
    {
        return new ResearchCompanyWebsiteAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: auth()->user(),
            website: $website,
            handler: $handler,
            collector: $collector,
        );
    }

    /**
     * @param list<array{url: string, title: ?string, content: string}> $pages
     */
    private function collector(array $pages): WebsitePageCollector
    {
        $collector = Mockery::mock(WebsitePageCollector::class);
        $collector->shouldReceive('collect')->once()->andReturn(new CollectedPages(self::HOME, 'tavily', $pages));

        return $collector;
    }

    private function handler(int $pages = 2): CompanyResearchAgent
    {
        $handler = Mockery::mock(CompanyResearchAgent::class);
        $handler->shouldReceive('promptWithConfig')
            ->once()
            ->andReturn(new StructuredAgentResponse('r', $this->structured($pages), '', new TextUsage(1, 2), new Meta()));

        return $handler;
    }

    /**
     * @return list<array{url: string, title: ?string, content: string}>
     */
    private function pages(): array
    {
        return [
            ['url' => self::HOME, 'title' => 'Acme', 'content' => 'We make chairs.'],
            ['url' => self::HOME . '/about', 'title' => 'About', 'content' => 'Founded in 1999 in Santo Domingo.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structured(int $pages = 2): array
    {
        $rewrites = [
            ['url' => self::HOME, 'title' => 'Acme', 'sections' => "Where is the showroom? Acme's showroom is at 1 Main St, Santo Domingo.\n\nWhat does Acme sell? Office chairs."],
            ['url' => self::HOME . '/about', 'title' => 'About', 'sections' => 'When was Acme founded? Acme was founded in 1999.'],
        ];

        return [
            'profile' => ['name' => 'Acme', 'industry' => 'Office furniture', 'summary' => 'Acme makes chairs.', 'confidence' => 0.8],
            'pages' => array_slice($rewrites, 0, $pages),
        ];
    }

    /**
     * @return array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}>
     */
    private function search(): array
    {
        return KnowledgeComponents::store($this->kanvasApp)->search(
            KnowledgeComponents::embedder($this->kanvasApp)->embed('showroom'),
            KnowledgeScope::forTenant($this->kanvasApp->getId(), $this->company->getId()),
            10,
        );
    }

    private function row(): CompanyOnboarding
    {
        return CompanyOnboarding::forCompany($this->kanvasApp, $this->company);
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

    public function testAnUnexpectedFailureStoresAGenericCodeNotTheMessage(): void
    {
        $collector = Mockery::mock(WebsitePageCollector::class);
        $collector->shouldReceive('collect')->once()->andThrow(new RuntimeException('curl: could not reach internal-db-10.0.0.5'));
        $handler = Mockery::mock(CompanyResearchAgent::class);
        $handler->shouldReceive('promptWithConfig')->never();

        $result = $this->action($collector, $handler)->execute();

        $this->assertSame(ResearchStatusEnum::FAILED, $result->status);
        $this->assertSame(ResearchCompanyWebsiteAction::RESEARCH_FAILED, $result->error);
        $this->assertSame(ResearchCompanyWebsiteAction::RESEARCH_FAILED, $this->row()->dataGet('research.error'));
    }

    public function testAPrivateHostStoresAGenericCodeWithoutTheResolvedAddress(): void
    {
        $handler = Mockery::mock(CompanyResearchAgent::class);
        $handler->shouldNotReceive('promptWithConfig');

        $result = $this->action(null, $handler, 'http://10.0.0.9')->execute();

        $this->assertSame(ResearchStatusEnum::FAILED, $result->status);
        $this->assertSame(ResearchCompanyWebsiteAction::WEBSITE_NOT_REACHABLE, $result->error);
        $this->assertStringNotContainsString('10.0.0.9', (string) $this->row()->dataGet('research.error'));
    }
}
