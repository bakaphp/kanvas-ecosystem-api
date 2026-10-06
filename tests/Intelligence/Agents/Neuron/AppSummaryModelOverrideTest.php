<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Agents\Neuron\Middleware\KanvasSummarization;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * Serial: app settings (Redis). Summaries are written by the agent's own provider unless the app names a
 * cheaper model for them; the Social note then records that model, not the agent's.
 */
#[Group('serial')]
class AppSummaryModelOverrideTest extends TestCase
{
    use MakesAgents;

    private Apps $kanvasApp;

    private mixed $previousGeminiKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->del(AgentRunConfigurationEnum::SUMMARY_MODEL->value);
        $this->kanvasApp->del(AgentRunConfigurationEnum::SUMMARY_PROVIDER->value);
        $this->previousGeminiKey = $this->kanvasApp->get(ConfigurationEnum::GEMINI_KEY->value);

        if (! is_string($this->previousGeminiKey) || $this->previousGeminiKey === '') {
            $this->kanvasApp->set(ConfigurationEnum::GEMINI_KEY->value, 'test-only');
        }
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(AgentRunConfigurationEnum::SUMMARY_MODEL->value);
        $this->kanvasApp->del(AgentRunConfigurationEnum::SUMMARY_PROVIDER->value);

        if (! is_string($this->previousGeminiKey) || $this->previousGeminiKey === '') {
            $this->kanvasApp->del(ConfigurationEnum::GEMINI_KEY->value);
        }

        parent::tearDown();
    }

    public function testWithoutASettingTheAgentsOwnProviderWritesTheSummary(): void
    {
        $this->assertNull($this->providerOf($this->summarization()));
    }

    public function testAnAppCanNameACheaperModelForSummaries(): void
    {
        $this->kanvasApp->set(AgentRunConfigurationEnum::SUMMARY_PROVIDER->value, 'gemini');
        $this->kanvasApp->set(AgentRunConfigurationEnum::SUMMARY_MODEL->value, 'gemini-2.5-flash-lite');

        $summarization = $this->summarization();
        $provider = $this->providerOf($summarization);

        $this->assertInstanceOf(KanvasGemini::class, $provider);
        $this->assertSame('gemini-2.5-flash-lite', $provider->getModel());
        $this->assertSame(
            'gemini-2.5-flash-lite',
            new ReflectionProperty($summarization, 'model')->getValue($summarization),
            'The Social note prices the summary by the model that wrote it'
        );
    }

    public function testACompanyCanPointSummariesAtOneOfItsLlmConfigs(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $config = AgentLlmConfigFactory::new()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->create(['provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'api_key' => 'config-key', 'is_active' => true]);
        $company->set(AgentRunConfigurationEnum::SUMMARY_LLM_CONFIG->value, $config->getId());

        try {
            $provider = $this->providerOf($this->summarization());
        } finally {
            $company->del(AgentRunConfigurationEnum::SUMMARY_LLM_CONFIG->value);
        }

        $this->assertInstanceOf(KanvasGemini::class, $provider);
        $this->assertSame('gemini-2.5-flash-lite', $provider->getModel());
    }

    private function summarization(): KanvasSummarization
    {
        $user = auth()->user();
        $handler = new SystemUserAgent();
        $handler->setConfiguration(agent: $this->makeAgentFor($user), entity: $user, user: $user);

        return new ReflectionMethod($handler, 'summarization')->invoke($handler);
    }

    private function providerOf(KanvasSummarization $summarization): mixed
    {
        return new ReflectionProperty($summarization, 'provider')->getValue($summarization);
    }
}
