<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations\Enrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Recommendations\Enrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentAgentService;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Recommendations\Enums\SemanticProfileStrategyEnum;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Laravel\Ai\Enums\Lab;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class ProductEnrichmentWithoutAgentTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'inventory', 'social'];

    public function testResolveAgentIsNullWhenTheAppHasNone(): void
    {
        $app = app(Apps::class);
        $this->removeEnrichmentAgents($app);

        $this->assertNull(ProductEnrichmentAgentService::resolveAgent($app));
    }

    public function testAnExplicitAgentThatDoesNotExistStillThrows(): void
    {
        $app = app(Apps::class);
        $this->removeEnrichmentAgents($app);

        $this->expectException(ValidationException::class);

        ProductEnrichmentAgentService::resolveAgent($app, 999999999);
    }

    public function testEnrichesOnTheDefaultModelAndTheAppsGeminiProviderWithoutAnAgent(): void
    {
        $product = $this->makeProduct();

        ProductEnrichmentAgent::fake([$this->structuredResponse()]);

        $result = new EnrichProductAction($product)->execute();

        $this->assertSame('enriched', $result['status']);
        $this->assertSame(ProductEnrichmentAgent::DEFAULT_MODEL, $result['usage']['model']);
        $this->assertStringContainsString('cordless drill', (string) $product->get(SearchFieldEnum::BLURB->value));
        $this->assertSame(['elegant'], $product->tags()->pluck('name')->all());

        ProductEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $prompt->provider->name() === Lab::Gemini->value
                && $prompt->model === ProductEnrichmentAgent::DEFAULT_MODEL,
        );
    }

    public function testTheAppSettingChoosesTheModelWhenThereIsNoAgent(): void
    {
        $product = $this->makeProduct();
        $this->useModel($product->app, 'gemini-2.5-flash');

        ProductEnrichmentAgent::fake([$this->structuredResponse()]);

        new EnrichProductAction($product)->execute();

        ProductEnrichmentAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'gemini-2.5-flash');
    }

    public function testACompanyLlmConfigRunsEnrichmentOnAnyProviderWithoutAnAgent(): void
    {
        $product = $this->makeProduct();
        $config = AgentLlmConfig::factory()->create([
            'apps_id' => $product->apps_id,
            'companies_id' => $product->companies_id,
            'provider' => AgentLlmProviderEnum::DEEPSEEK->value,
            'model' => 'deepseek-chat',
        ]);
        $product->company->set(ConfigurationEnum::ENRICHMENT_LLM_CONFIG->value, $config->getId());

        try {
            ProductEnrichmentAgent::fake([$this->structuredResponse()]);

            new EnrichProductAction($product)->execute();

            ProductEnrichmentAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'deepseek-chat');
        } finally {
            $product->company->del(ConfigurationEnum::ENRICHMENT_LLM_CONFIG->value);
        }
    }

    public function testAnotherCompanysLlmConfigIsIgnored(): void
    {
        $product = $this->makeProduct();
        $foreign = AgentLlmConfig::factory()->create([
            'apps_id' => $product->apps_id,
            'companies_id' => Companies::factory()->create()->getId(),
            'provider' => AgentLlmProviderEnum::DEEPSEEK->value,
            'model' => 'deepseek-chat',
        ]);
        $product->company->set(ConfigurationEnum::ENRICHMENT_LLM_CONFIG->value, $foreign->getId());

        try {
            ProductEnrichmentAgent::fake([$this->structuredResponse()]);

            new EnrichProductAction($product)->execute();

            ProductEnrichmentAgent::assertPrompted(fn ($prompt): bool => $prompt->model === ProductEnrichmentAgent::DEFAULT_MODEL);
        } finally {
            $product->company->del(ConfigurationEnum::ENRICHMENT_LLM_CONFIG->value);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function thinkingByModel(): array
    {
        return [
            'flash-lite never thinks and rejects every level' => ['gemini-2.5-flash-lite', []],
            '2.5 flash takes low, not minimal' => ['gemini-2.5-flash', ['generation_config' => ['thinking_level' => 'low']]],
            'gemini 3 takes minimal' => ['gemini-3-flash-preview', ['generation_config' => ['thinking_level' => 'minimal']]],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('thinkingByModel')]
    public function testGeminiThinkingIsTurnedDownForTheModelInUse(string $model, array $expected): void
    {
        $app = app(Apps::class);
        $this->useModel($app, $model);

        $agent = new ProductEnrichmentAgent();
        $agent->setConfigurationWithoutAgent($app, auth()->user()->getCurrentCompany());

        $this->assertSame($expected, $agent->providerOptions(Lab::Gemini));
        $this->assertSame($expected, $agent->providerOptions('gemini'));
    }

    public function testOtherProvidersGetNoProviderOptions(): void
    {
        $app = app(Apps::class);
        $this->useModel($app, 'gemini-2.5-flash');

        $agent = new ProductEnrichmentAgent();
        $agent->setConfigurationWithoutAgent($app, auth()->user()->getCurrentCompany());

        $this->assertSame([], $agent->providerOptions(Lab::OpenAI));
        $this->assertSame([], $agent->providerOptions(Lab::Anthropic));
    }

    public function testTheRequestSentToGeminiCarriesTheThinkingLevelAndReportsRealUsage(): void
    {
        config(['ai.providers.gemini.key' => 'test-key']);
        $product = $this->makeProduct();
        $this->useModel($product->app, 'gemini-2.5-flash');

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => json_encode($this->structuredResponse())]],
            ]],
            'usage' => ['total_input_tokens' => 1300, 'total_output_tokens' => 180, 'total_thought_tokens' => 20],
        ])]);

        $result = new EnrichProductAction($product)->execute();

        Http::assertSent(
            fn (Request $request): bool => $request['model'] === 'gemini-2.5-flash'
                && $request['generation_config']['thinking_level'] === 'low',
        );
        $this->assertSame(
            ['input_tokens' => 1300, 'output_tokens' => 200, 'model' => 'gemini-2.5-flash'],
            $result['usage'],
        );
    }

    private function useModel(Apps $app, string $model): void
    {
        $this->rememberAppSettings(ConfigurationEnum::ENRICHMENT_MODEL->value);
        $app->set(ConfigurationEnum::ENRICHMENT_MODEL->value, $model);
    }

    private function removeEnrichmentAgents(Apps $app): void
    {
        Agent::fromApp($app)
            ->whereHas('type', fn ($query) => $query->where('handler', ProductEnrichmentAgent::class))
            ->update(['is_deleted' => 1]);
    }

    private function makeProduct(): Products
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = Companies::factory()->create();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());
        new InventorySetup($app, $user, $company)->run();
        $this->removeEnrichmentAgents($app);

        $this->rememberAppSettings(
            ConfigurationEnum::ENRICHMENT_MODEL->value,
            ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value,
        );
        $app->set(ConfigurationEnum::ENRICHMENT_MODEL->value, ProductEnrichmentAgent::DEFAULT_MODEL);
        $app->set(ConfigurationEnum::SEMANTIC_PROFILE_STRATEGY->value, SemanticProfileStrategyEnum::GENERIC->value);

        return new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: 'Drill ' . uniqid(),
                sku: 'DR-' . uniqid(),
            ),
            $user,
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredResponse(): array
    {
        return [
            'audience' => ['unisex'],
            'occasion' => [],
            'interests' => [],
            'tags' => ['elegant', 'not-a-real-tag'],
            'blurb_es' => 'Un taladro inalambrico.',
            'blurb_en' => 'A cordless drill.',
        ];
    }
}
