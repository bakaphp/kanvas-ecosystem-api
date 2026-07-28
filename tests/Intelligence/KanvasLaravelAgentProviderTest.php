<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Illuminate\Support\Facades\Config;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Laravel\Ai\Enums\Lab;
use Stringable;
use Tests\TestCase;

final class KanvasLaravelAgentProviderTest extends TestCase
{
    private function stub(): KanvasLaravelAgent
    {
        return new class () extends KanvasLaravelAgent {
            public function instructions(): Stringable|string
            {
                return 'test';
            }

            public function agentTools(): iterable
            {
                return [];
            }

            public function exposeProvider(): ?Lab
            {
                return $this->getProvider();
            }

            public function exposeModel(): ?string
            {
                return $this->getModel();
            }

            public function exposeApplyCredentials(): \Closure
            {
                return $this->applyTenantProviderCredentials();
            }
        };
    }

    private function agentWithConfig(array $configAttributes): AgentRecord
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $llmConfig = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create($configAttributes);

        return AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_llm_config_id' => $llmConfig->getId(), 'config' => []]);
    }

    public function testSelectedConfigDrivesLaravelProviderAndModel(): void
    {
        $agent = $this->agentWithConfig([
            'provider' => AgentLlmProviderEnum::OPENAI_LIKE->value,
            'base_uri' => 'https://box.example/v1',
            'model' => 'Qwen-box',
        ]);

        $handler = $this->stub();
        $handler->setConfiguration($agent);

        $this->assertSame(Lab::OpenAICompatible, $handler->exposeProvider());
        $this->assertSame('Qwen-box', $handler->exposeModel());
    }

    public function testAnthropicConfigMapsToAnthropicLab(): void
    {
        $agent = $this->agentWithConfig([
            'provider' => AgentLlmProviderEnum::ANTHROPIC->value,
            'base_uri' => null,
            'model' => 'claude-opus-4-8',
        ]);

        $handler = $this->stub();
        $handler->setConfiguration($agent);

        $this->assertSame(Lab::Anthropic, $handler->exposeProvider());
        $this->assertSame('claude-opus-4-8', $handler->exposeModel());
    }

    public function testCredentialInjectionSetsAndRestoresOpenAiCompatibleConfig(): void
    {
        $agent = $this->agentWithConfig([
            'provider' => AgentLlmProviderEnum::OPENAI_LIKE->value,
            'base_uri' => 'https://box.example/v1',
            'api_key' => 'box-secret',
            'model' => 'Qwen-box',
        ]);

        $originalUrl = Config::get('ai.providers.openai-compatible.url');

        $handler = $this->stub();
        $handler->setConfiguration($agent);
        $restore = $handler->exposeApplyCredentials();

        $this->assertSame('https://box.example/v1', Config::get('ai.providers.openai-compatible.url'));
        $this->assertSame('box-secret', Config::get('ai.providers.openai-compatible.key'));
        $this->assertSame('openai-compatible', Config::get('ai.providers.openai-compatible.driver'));

        $restore();

        $this->assertSame($originalUrl, Config::get('ai.providers.openai-compatible.url'));
    }
}
