<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\XAI\Grok;
use ReflectionProperty;
use Tests\TestCase;

final class AgentProviderServiceTest extends TestCase
{
    /**
     * App-level provider settings are shared, persisted state. Snapshot them so each test starts
     * from a clean baseline AND the real app config (e.g. a live box default) survives the run.
     *
     * @var array<string, mixed>
     */
    private array $originalSettings = [];

    private function scopedSettings(): array
    {
        return [
            ConfigurationEnum::AI_PROVIDER->value,
            ConfigurationEnum::AI_PROVIDER_BASE_URI->value,
            ConfigurationEnum::AI_PROVIDER_KEY->value,
            ConfigurationEnum::AI_PROVIDER_MODEL->value,
            ConfigurationEnum::GEMINI_KEY->value,
            ConfigurationEnum::GEMINI_MODEL->value,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        foreach ($this->scopedSettings() as $key) {
            $this->originalSettings[$key] = $app->get($key);
            $app->del($key);
        }
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);
        foreach ($this->originalSettings as $key => $value) {
            if ($value === null || $value === '') {
                $app->del($key);
            } else {
                $app->set($key, $value);
            }
        }

        parent::tearDown();
    }

    public function testResolvesOpenAiCompatibleBoxFromAgentConfig(): void
    {
        $app = app(Apps::class);
        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create([
                'config' => [
                    'llm_provider' => AgentLlmProviderEnum::OPENAI_LIKE->value,
                    'base_uri' => 'https://box.example/v1',
                    'key' => 'box-key',
                    'model' => 'Qwen3.6-35B-A3B-4bit',
                ],
            ]);

        $provider = AgentProviderService::resolve($agent);

        $this->assertInstanceOf(OpenAILike::class, $provider);
        $this->assertSame('https://box.example/v1', $this->readProp($provider, 'baseUri'));
        $this->assertSame('Qwen3.6-35B-A3B-4bit', $this->readProp($provider, 'model'));
        $this->assertSame('Qwen3.6-35B-A3B-4bit', AgentProviderService::resolveModel($agent));
    }

    public function testSelectedLlmConfigWinsOverInlineAndGlobal(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $app->set(ConfigurationEnum::AI_PROVIDER->value, AgentLlmProviderEnum::GEMINI->value);

        $llmConfig = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'provider' => AgentLlmProviderEnum::OPENAI_LIKE->value,
                'base_uri' => 'https://named-config.example/v1',
                'api_key' => 'named-config-key',
                'model' => 'Qwen-named',
            ]);

        // Inline config points at Gemini; the selected named config must still win.
        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_llm_config_id' => $llmConfig->getId(),
                'config' => ['llm_provider' => AgentLlmProviderEnum::GEMINI->value],
            ]);

        $provider = AgentProviderService::resolve($agent);

        $this->assertInstanceOf(OpenAILike::class, $provider);
        $this->assertSame('https://named-config.example/v1', $this->readProp($provider, 'baseUri'));
        $this->assertSame('Qwen-named', AgentProviderService::resolveModel($agent));
    }

    public function testResolvesEachNativeNeuronProvider(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $cases = [
            [AgentLlmProviderEnum::ANTHROPIC, Anthropic::class],
            [AgentLlmProviderEnum::OPENAI, OpenAI::class],
            [AgentLlmProviderEnum::MISTRAL, Mistral::class],
            [AgentLlmProviderEnum::DEEPSEEK, Deepseek::class],
            [AgentLlmProviderEnum::XAI, Grok::class],
            [AgentLlmProviderEnum::OLLAMA, Ollama::class],
        ];

        foreach ($cases as [$providerEnum, $expectedClass]) {
            $cfg = AgentLlmConfigFactory::new()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->create(['provider' => $providerEnum->value]);

            $agent = AgentFactory::new()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->create(['agent_llm_config_id' => $cfg->getId(), 'config' => []]);

            $this->assertInstanceOf(
                $expectedClass,
                AgentProviderService::resolve($agent),
                "provider {$providerEnum->value} should build {$expectedClass}"
            );
        }
    }

    public function testStaleLlmConfigIdFallsThroughToNextSource(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::GEMINI_KEY->value, 'gemini-key');

        // A dangling id must be ignored and resolution fall through to the inline provider.
        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create([
                'agent_llm_config_id' => 999999999,
                'config' => ['llm_provider' => AgentLlmProviderEnum::GEMINI->value],
            ]);

        $this->assertInstanceOf(Gemini::class, AgentProviderService::resolve($agent));
    }

    public function testFallsBackToAppLevelOpenAiProviderWhenAgentHasNoConfig(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::AI_PROVIDER->value, AgentLlmProviderEnum::OPENAI_LIKE->value);
        $app->set(ConfigurationEnum::AI_PROVIDER_BASE_URI->value, 'https://app-box.example/v1');
        $app->set(ConfigurationEnum::AI_PROVIDER_KEY->value, 'app-box-key');
        $app->set(ConfigurationEnum::AI_PROVIDER_MODEL->value, 'Qwen-app');

        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create(['config' => []]);

        $provider = AgentProviderService::resolve($agent);

        $this->assertInstanceOf(OpenAILike::class, $provider);
        $this->assertSame('https://app-box.example/v1', $this->readProp($provider, 'baseUri'));
    }

    public function testDefaultsToGeminiWhenNoProviderConfigured(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::GEMINI_KEY->value, 'gemini-key');

        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create(['config' => []]);

        $this->assertInstanceOf(Gemini::class, AgentProviderService::resolve($agent));
    }

    public function testPassesConfiguredParametersToGemini(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::GEMINI_KEY->value, 'gemini-key');

        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create([
                'config' => [
                    'llm_provider' => AgentLlmProviderEnum::GEMINI->value,
                    'model' => 'gemini-model',
                    'parameters' => ['temperature' => 0.1],
                ],
            ]);

        $provider = AgentProviderService::resolve($agent);

        $this->assertInstanceOf(Gemini::class, $provider);
        $this->assertSame(['temperature' => 0.1], $this->readProp($provider, 'parameters'));
    }

    private function readProp(object $object, string $property): mixed
    {
        return new ReflectionProperty($object, $property)->getValue($object);
    }
}
