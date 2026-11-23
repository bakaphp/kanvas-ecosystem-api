<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Closure;
use Illuminate\Support\Facades\Config;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent as AgentRecord;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\BedrockClientService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Laravel\Ai\Enums\Lab;
use Mockery;
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

            public function exposeApplyCredentials(): Closure
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

    public function testABedrockConfigMapsToTheBedrockLabWithItsKeyAndRegionForOnePrompt(): void
    {
        $agent = $this->agentWithConfig([
            'provider' => AgentLlmProviderEnum::BEDROCK->value,
            'base_uri' => null,
            'api_key' => 'bedrock-secret',
            'model' => 'global.anthropic.claude-haiku-5-5',
            'config' => ['region' => 'us-west-2'],
        ]);

        Config::set('ai.providers.bedrock.key', 'platform-key');
        Config::set('ai.providers.bedrock.region', 'us-east-1');

        $handler = $this->stub();
        $handler->setConfiguration($agent);

        $this->assertSame(Lab::Bedrock, $handler->exposeProvider());
        $this->assertSame('global.anthropic.claude-haiku-5-5', $handler->exposeModel());

        $restore = $handler->exposeApplyCredentials();

        $this->assertSame('bedrock-secret', Config::get('ai.providers.bedrock.key'));
        $this->assertSame('us-west-2', Config::get('ai.providers.bedrock.region'));

        $restore();

        $this->assertSame('platform-key', Config::get('ai.providers.bedrock.key'));
        $this->assertSame('us-east-1', Config::get('ai.providers.bedrock.region'));
    }

    public function testAGeminiModelLeftOnABedrockAgentIsNeverSentToBedrock(): void
    {
        $agent = $this->agentWithConfig([
            'provider' => AgentLlmProviderEnum::BEDROCK->value,
            'base_uri' => null,
            'model' => 'gemini-3.8-flash',
        ]);

        $handler = $this->stub();
        $handler->setConfiguration($agent);

        $this->assertSame(Lab::Bedrock, $handler->exposeProvider());
        $this->assertSame(AgentProviderService::resolveModel($agent), $handler->exposeModel());
        $this->assertSame(BedrockClientService::defaultModel(), $handler->exposeModel());

        $agent->setRelation('llmConfig', null);
        $agent->agent_llm_config_id = null;
        $agent->setRelation('model', new AgentModel()->forceFill([
            'config' => [
                'provider' => AgentLlmProviderEnum::BEDROCK->value,
                'model' => 'gemini-3.8-flash',
            ],
        ]));

        $this->assertSame(Lab::Bedrock, $handler->exposeProvider());
        $this->assertSame(BedrockClientService::defaultModel(), $handler->exposeModel());
    }

    public function testAppCredentialsAreRestoredAfterThePrompt(): void
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(static fn (string $key): ?string => match ($key) {
            ConfigurationEnum::GEMINI_KEY->value => 'app-gemini-key',
            ConfigurationEnum::BEDROCK_KEY->value => 'app-bedrock-key',
            default => null,
        });
        $agent = new AgentRecord();
        $agent->setRelation('app', $app);
        $agent->setRelation('company', null);
        Config::set('ai.providers.gemini.key', 'platform-gemini-key');
        Config::set('ai.providers.bedrock.key', 'platform-bedrock-key');

        $handler = $this->stub();
        $handler->setConfiguration($agent);
        $restore = $handler->exposeApplyCredentials();

        $this->assertSame('app-gemini-key', Config::get('ai.providers.gemini.key'));
        $this->assertSame('app-bedrock-key', Config::get('ai.providers.bedrock.key'));

        $restore();

        $this->assertSame('platform-gemini-key', Config::get('ai.providers.gemini.key'));
        $this->assertSame('platform-bedrock-key', Config::get('ai.providers.bedrock.key'));
    }
}
