<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations\Enrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Recommendations\Enrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\CustomFieldEnum;
use Kanvas\Inventory\Recommendations\Enrichment\Jobs\EnrichProductJob;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentFingerprintService;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum as RecommendationConfigurationEnum;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

class BackfillProductEnrichmentCommandTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    // 'intelligence' holds Agent/AgentType. Without it a sibling test's
    // enrichment agent survives and the no-agent guard silently passes.
    protected $connectionsToTransact = [null, 'inventory', 'intelligence'];

    public function testStrategySettingSwapsTheBlurbFramingButKeepsTheMechanics(): void
    {
        $app = app(Apps::class);
        $this->rememberAppSettings(RecommendationConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value);

        $agent = new ProductEnrichmentAgent();
        $agent->setConfiguration(agent: $this->makeEnrichmentAgent($app, Companies::factory()->create()), app: $app);

        $app->set(RecommendationConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, 'generic');
        $generic = (string) $agent->instructions();

        $app->set(RecommendationConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, 'gift');
        $gift = (string) $agent->instructions();

        $this->assertStringContainsString('GIFT catalog', $gift);
        $this->assertStringNotContainsString('GIFT catalog', $generic);

        // The anti-filler rules are mechanics — swapping a vertical must not
        // take them with it, which is what an instructions override does.
        foreach ([$gift, $generic] as $prompt) {
            $this->assertStringContainsString('DISCRIMINATING', $prompt);
            $this->assertStringContainsString('NEVER write filler', $prompt);
            $this->assertStringContainsString('blurb_es', $prompt);
        }
    }

    public function testSkipsAProductWhoseCompanyDoesNotResolveInsteadOfFataling(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $product = $this->makeProduct($app, $company);

        // In production this is normally a SOFT-DELETED company: the row exists but
        // Companies carries a SoftDeletingScope, so the relation resolves null.
        // Pointing at a missing id reproduces the same null.
        $product->companies_id = 999999999;
        $product->unsetRelation('company');
        $this->assertNull($product->company, 'Test setup should produce a company-less product.');

        $result = new EnrichProductAction($product)->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('no company', $result['reason']);
    }

    public function testRunsOnTheShippedDefaultsWhenTheAppHasNoEnrichmentAgent(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $this->removeEnrichmentAgents($app);
        $this->makeProduct($app, $company);

        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
        ])->assertExitCode(0);

        Queue::assertPushed(EnrichProductJob::class, 1);
    }

    public function testFailsFastWhenTheExplicitAgentDoesNotExist(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $this->makeProduct($app, Companies::factory()->create());

        // Without this guard the command would queue one job per product, each
        // of which fails identically on the missing agent.
        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--agent_id' => 999999999,
        ])->assertExitCode(1);

        Queue::assertNotPushed(EnrichProductJob::class);
    }

    public function testSyncPrintsTheBlurbTokensAndCostPerProductThenTheTotals(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());
        $this->removeEnrichmentAgents($app);
        $this->makeProduct($app, $company);

        ProductEnrichmentAgent::fake([[
            'audience' => ['unisex'],
            'occasion' => [],
            'interests' => [],
            'tags' => [],
            'blurb_es' => 'Un producto de prueba.',
            'blurb_en' => 'A test product.',
        ]]);

        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
            '--sync' => true,
        ])
            ->expectsOutputToContain('A test product.')
            ->expectsOutputToContain('0 in / 0 out tokens')
            ->expectsOutputToContain('Tokens: 0 in / 0 out. Estimated cost:')
            ->assertExitCode(0);
    }

    public function testQueuesOneJobPerProduct(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $this->makeEnrichmentAgent($app, $company);

        $this->makeProduct($app, $company);
        $this->makeProduct($app, $company);

        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
        ])->assertExitCode(0);

        Queue::assertPushed(EnrichProductJob::class, 2);
    }

    public function testAReRunQueuesOnlyWhatChangedUnlessForced(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $this->makeEnrichmentAgent($app, $company);

        $enriched = $this->makeProduct($app, $company);
        $enriched->set(CustomFieldEnum::ENRICHMENT_HASH->value, ProductEnrichmentFingerprintService::for($enriched));
        $this->makeProduct($app, $company);

        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
        ])->expectsOutputToContain('Skipped 1 already enriched')->assertExitCode(0);

        Queue::assertPushed(EnrichProductJob::class, 1);

        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
            '--force' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(
            EnrichProductJob::class,
            fn (EnrichProductJob $job): bool => $job->product->getId() === $enriched->getId(),
        );
    }

    public function testLimitCapsTheRun(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $this->makeEnrichmentAgent($app, $company);

        $this->makeProduct($app, $company);
        $this->makeProduct($app, $company);
        $this->makeProduct($app, $company);

        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
            '--limit' => 2,
        ])->assertExitCode(0);

        Queue::assertPushed(EnrichProductJob::class, 2);
    }

    public function testDoesNotTouchAnotherCompanysCatalog(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $this->makeEnrichmentAgent($app, $company);

        $this->makeProduct($app, $company);
        $this->makeProduct($app, Companies::factory()->create());

        $this->artisan('kanvas-inventory:backfill-product-enrichment', [
            'app_id' => $app->getId(),
            '--company_id' => $company->getId(),
        ])->assertExitCode(0);

        Queue::assertPushed(EnrichProductJob::class, 1);
    }

    public function testJobIsRoutedToItsOwnQueue(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $product = $this->makeProduct($app, $company);

        // A dedicated queue keeps a catalog-wide backfill from starving the
        // shared worker — it must not silently fall back to `default`.
        $this->assertSame('product-enrichment', new EnrichProductJob($app, $product)->queue);
    }

    private function removeEnrichmentAgents(Apps $app): void
    {
        Agent::fromApp($app)
            ->whereHas('type', fn ($query) => $query->where('handler', ProductEnrichmentAgent::class))
            ->update(['is_deleted' => 1]);
    }

    private function makeEnrichmentAgent(Apps $app, Companies $company): Agent
    {
        $type = AgentType::factory()->create([
            'apps_id' => $app->getId(),
            'handler' => ProductEnrichmentAgent::class,
            'provider' => 'laravel',
        ]);

        return Agent::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_type_id' => $type->getId(),
        ]);
    }

    private function makeProduct(Apps $app, Companies $company): Products
    {
        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }
}
