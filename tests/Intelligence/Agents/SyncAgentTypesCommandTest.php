<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Laravel\Inventory\AgentInventoryAssistance;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Commerce\CommerceAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Services\AgentTypeDiscoveryService;
use stdClass;
use Tests\TestCase;

class SyncAgentTypesCommandTest extends TestCase
{
    public function testDiscoverFindsAnnotatedHandlersWithDerivedProvider(): void
    {
        $byClass = collect(new AgentTypeDiscoveryService()->discover())->keyBy('class');

        $laravel = $byClass[KanvasGenericLaravelAgent::class] ?? null;
        $this->assertNotNull($laravel, 'Generic Laravel agent should be discovered');
        $this->assertSame('Generic Laravel Agent', $laravel['name']);
        $this->assertSame('laravel', $laravel['provider']);

        $neuron = $byClass[KanvasGenericNeuronAgent::class] ?? null;
        $this->assertNotNull($neuron, 'Generic Neuron agent should be discovered');
        $this->assertSame('neuron', $neuron['provider']);
    }

    public function testInstructionsAreLoadedFromTheHandlerClassNotTheAttribute(): void
    {
        $byClass = collect(new AgentTypeDiscoveryService()->discover())->keyBy('class');

        // Static handler — its hardcoded instructions() are snapshot into the catalog.
        $assistant = $byClass[AgentInventoryAssistance::class] ?? null;
        $this->assertNotNull($assistant);
        $this->assertStringContainsString('inventory assistant', (string) $assistant['instructions']);

        // Role-driven handler — needs runtime context, so it computes per-turn and stores null.
        $sales = $byClass[SalesAgent::class] ?? null;
        $this->assertNotNull($sales);
        $this->assertNull($sales['instructions']);
    }

    public function testAgentTypeDefinitionFromClassReadsAttribute(): void
    {
        $meta = AgentTypeDefinition::fromClass(KanvasGenericNeuronAgent::class);

        $this->assertNotNull($meta);
        $this->assertSame('Generic Neuron Agent', $meta->name);
        $this->assertSame('neuron', $meta->provider);

        $this->assertNull(
            AgentTypeDefinition::fromClass(stdClass::class),
            'A class without the attribute returns null',
        );
    }

    public function testCommerceAgentDefinitionIsDiscoverableWithSoul(): void
    {
        $meta = AgentTypeDefinition::fromClass(CommerceAgent::class);

        $this->assertNotNull($meta);
        $this->assertSame('Commerce Agent', $meta->name);
        $this->assertSame('neuron', $meta->provider);
        $this->assertStringContainsString('Commerce teammate', (string) $meta->soul);

        $discovered = collect(new AgentTypeDiscoveryService()->discover())->keyBy('class');
        $this->assertArrayHasKey(CommerceAgent::class, $discovered->all());
    }

    public function testSyncCreatesGlobalAgentTypeRow(): void
    {
        $this->artisan('kanvas:intelligence:sync-agent-types')->assertSuccessful();

        $this->assertDatabaseHas(
            'agent_types',
            [
                'handler' => KanvasGenericNeuronAgent::class,
                'apps_id' => 0,
            ],
            'intelligence',
        );
    }

    /**
     * Descriptions are catalog copy an orchestrator reads when picking a teammate, so a column too
     * narrow for one silently truncates it — or, once MySQL is strict, fails the whole sync. This
     * replaces a hardcoded varchar(255) bound: it asserts the round-trip instead of the width, so it
     * stays honest whatever the column becomes.
     */
    public function testTheLongestDescriptionSurvivesTheRoundTrip(): void
    {
        $longest = collect(new AgentTypeDiscoveryService()->discover())
            ->sortByDesc(fn (array $entry): int => strlen((string) $entry['description']))
            ->first();

        $this->artisan('kanvas:intelligence:sync-agent-types')->assertSuccessful();

        $stored = AgentType::query()
            ->where('handler', $longest['class'])
            ->where('apps_id', 0)
            ->value('description');

        $this->assertSame(
            $longest['description'],
            $stored,
            $longest['name'] . ' description was altered on the way into agent_types',
        );
    }

    public function testSyncIsIdempotent(): void
    {
        $this->artisan('kanvas:intelligence:sync-agent-types')->assertSuccessful();
        $countAfterFirst = $this->globalAgentTypeCount();

        $this->artisan('kanvas:intelligence:sync-agent-types')->assertSuccessful();
        $countAfterSecond = $this->globalAgentTypeCount();

        $this->assertGreaterThanOrEqual(1, $countAfterFirst);
        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'Re-running the sync must not create duplicate agent type rows',
        );
    }

    private function globalAgentTypeCount(): int
    {
        return AgentType::query()->where('apps_id', 0)->count();
    }
}
