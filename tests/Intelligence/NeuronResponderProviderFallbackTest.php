<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\NeuronResponderProviderFallback;
use NeuronAI\Router\RouterProvider;
use ReflectionProperty;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

final class NeuronResponderProviderFallbackTest extends TestCase
{
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
            ->create(['provider' => AgentLlmProviderEnum::ANTHROPIC->value]);
        $globalFallback = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create(['provider' => AgentLlmProviderEnum::MISTRAL->value]);
        $inactive = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_active' => false]);

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
        $this->assertLessThan(
            array_search('llm_config_' . $globalFallback->getId(), $order, true),
            array_search('llm_config_' . $companyFallback->getId(), $order, true),
        );
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

    public function testAgentCanDisableProviderFallback(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

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

        $this->assertInstanceOf(\NeuronAI\Providers\OpenAI\OpenAI::class, $provider);
    }
}
