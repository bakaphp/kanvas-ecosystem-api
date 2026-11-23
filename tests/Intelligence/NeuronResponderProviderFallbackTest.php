<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasBedrock;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasOpenAIResponses;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\NeuronResponderProviderFallback;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\Router\RouterProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

final class NeuronResponderProviderFallbackTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testItBuildsFallbacksFromActiveTenantLlmConfigs(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $selected = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['provider' => AgentLlmProviderEnum::OPENAI->value]);
        $companyFallback = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'provider' => AgentLlmProviderEnum::ANTHROPIC->value,
                'is_routing_enabled' => true,
            ]);
        $globalFallback = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create([
                'provider' => AgentLlmProviderEnum::MISTRAL->value,
                'is_routing_enabled' => true,
            ]);
        $inactive = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_active' => false, 'is_routing_enabled' => true]);
        $routingDisabled = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_routing_enabled' => false]);

        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_llm_config_id' => $selected->getId(), 'config' => []]);

        $provider = new NeuronResponderProviderFallback()->wrap(new FakeNeuronProvider(), $agent);

        $this->assertInstanceOf(RouterProvider::class, $provider);
        $order = new ReflectionProperty(RouterProvider::class, 'fallbackOrder')->getValue($provider);
        $this->assertSame('primary', $order[0]);
        $this->assertContains('llm_config_' . $companyFallback->getId(), $order);
        $this->assertContains('llm_config_' . $globalFallback->getId(), $order);
        $this->assertNotContains('llm_config_' . $selected->getId(), $order);
        $this->assertNotContains('llm_config_' . $inactive->getId(), $order);
        $this->assertNotContains('llm_config_' . $routingDisabled->getId(), $order);
        $this->assertLessThan(
            array_search('llm_config_' . $globalFallback->getId(), $order, true),
            array_search('llm_config_' . $companyFallback->getId(), $order, true),
        );
    }

    #[Group('serial')]
    public function testABedrockAgentFailsOverToTheSecondBedrockModelUnlessTheAppNamesAnotherProvider(): void
    {
        $app = app(Apps::class);
        $settings = [
            ConfigurationEnum::AI_PROVIDER->value,
            ConfigurationEnum::AI_PROVIDER_MODEL->value,
            ConfigurationEnum::AI_FALLBACK_PROVIDER->value,
            ConfigurationEnum::AI_FALLBACK_MODEL->value,
            ConfigurationEnum::GEMINI_KEY->value,
            ConfigurationEnum::GEMINI_MODEL->value,
        ];
        $original = [];
        foreach ($settings as $key) {
            $original[$key] = $app->get($key);
            $app->del($key);
        }
        config([
            'ai.providers.bedrock.key' => 'platform-bedrock-key',
            'ai.providers.bedrock.models.text.fallback' => 'global.amazon.nova-2-lite-v1:0',
        ]);

        try {
            $agent = AgentFactory::new()
                ->withAppId($app->getId())
                ->withCompanyId(0)
                ->create(['config' => []]);

            $this->assertNull(AgentProviderService::appFallbackProvider($agent), 'A Gemini app gains no fallback.');

            $app->set(ConfigurationEnum::AI_PROVIDER->value, AgentLlmProviderEnum::BEDROCK->value);
            $fallback = AgentProviderService::appFallbackProvider($agent);
            $this->assertInstanceOf(KanvasBedrock::class, $fallback);
            $this->assertSame('global.amazon.nova-2-lite-v1:0', $fallback->getModel());

            $wrapped = new NeuronResponderProviderFallback()->wrap(new FakeNeuronProvider(), $agent);
            $this->assertSame(['primary', 'app_fallback'], new ReflectionProperty(RouterProvider::class, 'fallbackOrder')->getValue($wrapped));

            $app->set(ConfigurationEnum::GEMINI_KEY->value, 'gemini-key');
            $app->set(ConfigurationEnum::AI_FALLBACK_PROVIDER->value, AgentLlmProviderEnum::GEMINI->value);
            $fallback = AgentProviderService::appFallbackProvider($agent);
            $this->assertInstanceOf(KanvasGemini::class, $fallback);
            $this->assertSame('gemini-3.8-flash', $fallback->getModel());

            $app->set(ConfigurationEnum::AI_FALLBACK_MODEL->value, 'gemini-3.1-flash-lite');
            $this->assertSame('gemini-3.1-flash-lite', AgentProviderService::appFallbackProvider($agent)->getModel());

            $app->del(ConfigurationEnum::AI_FALLBACK_MODEL->value);
            $app->set(ConfigurationEnum::AI_FALLBACK_PROVIDER->value, 'none');
            $this->assertNull(AgentProviderService::appFallbackProvider($agent));

            $app->del(ConfigurationEnum::AI_FALLBACK_PROVIDER->value);
            $app->set(ConfigurationEnum::AI_PROVIDER_MODEL->value, 'global.amazon.nova-2-lite-v1:0');
            $this->assertNull(AgentProviderService::appFallbackProvider($agent), 'Never the same model twice.');
        } finally {
            foreach ($original as $key => $value) {
                $value === null || $value === '' ? $app->del($key) : $app->set($key, $value);
            }
        }
    }

    public function testItDoesNotUseConfigsFromAnotherCompany(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $otherCompanyId = $company->getId() + 999999;

        $otherCompanyConfig = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($otherCompanyId)
            ->create();

        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['config' => []]);
        $primary = new FakeNeuronProvider();

        $provider = new NeuronResponderProviderFallback()->wrap($primary, $agent);

        if ($provider instanceof RouterProvider) {
            $providers = new ReflectionProperty(RouterProvider::class, 'providers')->getValue($provider);
            $this->assertArrayNotHasKey('llm_config_' . $otherCompanyConfig->getId(), $providers);
        } else {
            $this->assertSame($primary, $provider);
        }
    }

    public function testItDoesNotRouteThroughAConfigUnlessExplicitlyEnabled(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        AgentLlmConfig::query()
            ->where('apps_id', $app->getId())
            ->whereIn('companies_id', [0, $company->getId()])
            ->update(['is_routing_enabled' => false]);

        AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_routing_enabled' => false]);

        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['config' => []]);
        $primary = new FakeNeuronProvider();

        $this->assertSame(
            $primary,
            new NeuronResponderProviderFallback()->wrap($primary, $agent),
        );
    }

    public function testAgentCanDisableProviderFallback(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_routing_enabled' => true]);

        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['config' => ['provider_fallback_enabled' => false]]);
        $primary = new FakeNeuronProvider();

        $this->assertSame(
            $primary,
            new NeuronResponderProviderFallback()->wrap($primary, $agent),
        );
    }

    public function testProviderServiceCanResolveAnExplicitTenantConfig(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $config = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['provider' => AgentLlmProviderEnum::OPENAI->value]);
        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['config' => []]);

        $provider = AgentProviderService::resolveConfig($agent, $config);

        $this->assertInstanceOf(KanvasOpenAIResponses::class, $provider);
    }
}
