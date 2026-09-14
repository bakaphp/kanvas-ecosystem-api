<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\AgentTypes\ClaudeAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\HireAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAgentTypesTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The hire's TYPE is what decides what it can physically do, and it was not a choice.
 *
 * `hire_agent` built every hire on `Generic Neuron Agent`, so an orchestrator asked to staff a
 * developer answered — accurately, for what it could actually produce — that the platform cannot
 * commit or push, while `Claude Agent` and `pi.dev Programming Agent` sat in the catalog beside it.
 */
final class HireAgentTypeChoiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'intelligence'];

    private ?Companies $company = null;

    private ?Agent $hirer = null;

    public function testItHiresOnTheNamedType(): void
    {
        $type = $this->neuronType();

        $result = $this->hire($type->name);

        $this->assertTrue($result['hired'], $result['message'] ?? '');
        $this->assertSame($type->name, $result['agent_type']);
        $this->assertSame(
            $type->getId(),
            (int) Agent::query()->whereKey($result['agent_id'])->first()->agent_type_id
        );
    }

    /** Names come from a model, so an invented one must come back with the real list rather than throw. */
    public function testAnUnknownTypeIsRefusedWithTheNamesThatDoExist(): void
    {
        $type = $this->neuronType();
        $before = $this->headcount();

        $result = $this->hire('Senior Backend Engineer');

        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('Senior Backend Engineer', $result['message']);
        $this->assertStringContainsString($type->name, $result['message']);
        $this->assertSame($before, $this->headcount());
    }

    public function testNamingNoTypeStillHiresTheGenericOne(): void
    {
        $result = $this->hire(null);

        $this->assertTrue($result['hired'], $result['message'] ?? '');
        $this->assertSame('Generic Neuron Agent', $result['agent_type']);
    }

    /**
     * A hosted type runs on a connector. Hiring onto one this company never connected produces an
     * agent that exists, accepts work and cannot run — a failure that surfaces days later on someone
     * else's task rather than here, where the decision was made.
     */
    public function testAHostedTypeIsRefusedWhenItsIntegrationIsNotConnected(): void
    {
        $type = $this->claudeType();
        $before = $this->headcount();

        $result = $this->hire($type->name);

        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('has not connected', $result['message']);
        $this->assertSame($before, $this->headcount());
    }

    public function testTheTypeListNamesWhatCanBeHiredAndWhatCannot(): void
    {
        $neuron = $this->neuronType();
        $claude = $this->claudeType();

        $result = new ListAgentTypesTool()
            ->withContext($this->kanvasApp(), $this->company(), $this->currentUser())
            ->__invoke();

        $available = [];

        foreach ($result['agent_types'] as $type) {
            $available[$type['name']] = $type['available'];
        }

        $this->assertTrue($available[$neuron->name] ?? null);
        $this->assertFalse($available[$claude->name] ?? null);
        $this->assertSame('Generic Neuron Agent', $result['default']);
    }

    /**
     * A hire on a coding type is not usable until an admin gives it a GitHub token and the repos it
     * may touch — and it cannot set either itself. Reported at hire time or discovered days later on
     * a task that fails; there is no third option.
     */
    public function testAHireThatStillNeedsCredentialsSaysSo(): void
    {
        $result = $this->hire('pi.dev Programming Agent');

        $this->assertTrue($result['hired'], $result['message'] ?? '');
        $this->assertNotSame([], $result['needs_from_an_admin']);
        $this->assertStringContainsString('PIDEV_GITHUB_TOKEN', implode(' ', $result['needs_from_an_admin']));
        $this->assertStringContainsString('PIDEV_ALLOWED_REPOS', implode(' ', $result['needs_from_an_admin']));
        $this->assertStringContainsString('NOT READY', $result['message']);
    }

    public function testAnOrdinaryHireIsReportedAsNeedingNothing(): void
    {
        $result = $this->hire(null);

        $this->assertSame([], $result['needs_from_an_admin']);
        $this->assertStringNotContainsString('NOT READY', $result['message']);
    }

    /**
     * The requirement is read off the handler class, not the catalog row: `sync-agent-types` never
     * refreshes an existing type's config, so anything stored there would be stale on every row that
     * already exists.
     */
    public function testTheTypeListCarriesWhatEachTypeStillNeeds(): void
    {
        $result = new ListAgentTypesTool()
            ->withContext($this->kanvasApp(), $this->company(), $this->currentUser())
            ->__invoke();

        $byName = array_column($result['agent_types'], null, 'name');

        $this->assertNotSame([], $byName['pi.dev Programming Agent']['requires_setup'] ?? []);
        $this->assertArrayNotHasKey(
            'requires_setup',
            $byName['Generic Neuron Agent'],
            'A type that needs nothing must not carry an empty key for the model to read into.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hire(?string $agentType): array
    {
        return new HireAgentTool($this->hiringAgent())
            ->withContext($this->kanvasApp(), $this->company(), $this->currentUser())
            ->forRequestingUser($this->currentUser())
            ->__invoke(
                name: 'Typed ' . fake()->unique()->lexify('?????'),
                role: 'Worker',
                instructions: 'Do the thing, or nothing when there is nothing to do.',
                agent_type: $agentType,
            );
    }

    private function headcount(): int
    {
        $this->hiringAgent();

        return Agent::query()->where('companies_id', $this->company()->getId())->count();
    }

    private function hiringAgent(): Agent
    {
        return $this->hirer ??= Agent::factory()
            ->withAppId($this->kanvasApp()->getId())
            ->withCompanyId($this->company()->getId())
            ->create([
                'user_id' => $this->currentUser()->getId(),
                'name' => 'Hirer ' . fake()->unique()->lexify('?????'),
                'is_active' => true,
            ]);
    }

    private function neuronType(): AgentType
    {
        return AgentType::factory()->withAppId($this->kanvasApp()->getId())->create([
            'name' => 'Runner ' . fake()->unique()->lexify('?????'),
            'provider' => 'neuron',
            'handler' => KanvasGenericNeuronAgent::class,
        ]);
    }

    private function claudeType(): AgentType
    {
        return AgentType::factory()->withAppId($this->kanvasApp()->getId())->create([
            'name' => 'Hosted ' . fake()->unique()->lexify('?????'),
            'provider' => 'claude',
            'handler' => ClaudeAgent::class,
        ]);
    }

    private function kanvasApp(): Apps
    {
        return app(Apps::class);
    }

    /** Its own company: hiring is capped per company and the shared one accrues agents all suite long. */
    private function company(): Companies
    {
        return $this->company ??= Companies::factory()->create([
            'users_id' => $this->currentUser()->getId(),
        ]);
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
