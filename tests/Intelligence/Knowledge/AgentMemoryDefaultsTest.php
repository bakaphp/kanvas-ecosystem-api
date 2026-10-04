<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use Kanvas\Intelligence\Agents\Neuron\Concerns\RunsDurably;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use NeuronAI\Agent\Agent;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\StubsTypesenseCredentials;

/**
 * Serial: both switches are app settings, which live in Redis and are shared by every parallel process.
 */
#[Group('serial')]
class AgentMemoryDefaultsTest extends TestCase
{
    use StubsTypesenseCredentials;

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->del(KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED->value);
        $this->kanvasApp->del(AgentRunConfigurationEnum::DURABLE_RUNS->value);
        $this->stubTypesenseCredentials($this->kanvasApp);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED->value);
        $this->kanvasApp->del(AgentRunConfigurationEnum::DURABLE_RUNS->value);
        $this->removeStubbedTypesenseCredentials($this->kanvasApp);

        parent::tearDown();
    }

    public function testMemoryIsOnUntilATenantTurnsItOff(): void
    {
        $this->assertTrue(KnowledgeComponents::memoryEnabled($this->kanvasApp), 'Unset means on');

        $this->kanvasApp->set(KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED->value, 0);
        $this->assertFalse(KnowledgeComponents::memoryEnabled($this->kanvasApp));

        $this->kanvasApp->set(KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED->value, 'true');
        $this->assertTrue(KnowledgeComponents::memoryEnabled($this->kanvasApp));
    }

    public function testKnowledgeStaysOptIn(): void
    {
        $this->assertFalse(KnowledgeComponents::knowledgeEnabled($this->kanvasApp), 'Uploaded knowledge keeps its own switch, off until set');
    }

    public function testMemoryNeedsSomewhereToWrite(): void
    {
        $this->assertTrue(SearchEngineResolver::hasTypesenseCredentials(['typesense_api_key' => 'k']));

        config(['scout.typesense.api_key' => '']);

        $this->assertFalse(SearchEngineResolver::hasTypesenseCredentials([]), 'No app key and no platform key');
        $this->assertFalse(SearchEngineResolver::hasTypesenseCredentials(['typesense_api_key' => '  ']));

        config(['scout.typesense.api_key' => 'platform']);

        $this->assertTrue(SearchEngineResolver::hasTypesenseCredentials([]), 'The platform key covers an app without its own');
    }

    public function testDurableRunsAreOnForAnOptedInTypeUntilATenantTurnsThemOff(): void
    {
        $agent = $this->durableAgent(optedIn: true);

        $this->assertTrue($agent->active(), 'Unset means on');

        $this->kanvasApp->set(AgentRunConfigurationEnum::DURABLE_RUNS->value, 0);
        $this->assertFalse($agent->active());

        $this->kanvasApp->set(AgentRunConfigurationEnum::DURABLE_RUNS->value, 1);
        $this->assertTrue($agent->active());
    }

    public function testTheAppSwitchNeverTurnsOnATypeThatDidNotOptIn(): void
    {
        $this->kanvasApp->set(AgentRunConfigurationEnum::DURABLE_RUNS->value, 1);

        $this->assertFalse($this->durableAgent(optedIn: false)->active());
    }

    private function durableAgent(bool $optedIn): Agent
    {
        $agent = new class () extends Agent {
            use RunsDurably;

            public ?Apps $app = null;
            public bool $optedIn = false;

            protected function durableRuns(): bool
            {
                return $this->optedIn;
            }

            public function active(): bool
            {
                return $this->durableRunsActive();
            }
        };
        $agent->app = $this->kanvasApp;
        $agent->optedIn = $optedIn;

        return $agent;
    }
}
