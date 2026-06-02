<?php

declare(strict_types=1);

namespace Tests\GraphQL\NervousSystem;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

class SetAgentToolMutationTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);
    }

    private function makeTool(): Tool
    {
        return new CreateToolAction(
            new ToolData(
                app: app(Apps::class),
                name: 'set-tool-test-' . uniqid(),
                frameworks: ['neuron'],
                toolType: ToolTypeEnum::CUSTOM,
            ),
        )->execute();
    }

    public function testEnabledTrueGrantsTheToolToTheAgent(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeTool();

        $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!, $enabled: Boolean!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: $enabled) {
                    is_active
                    is_deleted
                    tool { id }
                }
            }
        ', [
            'agent_id' => (string) $agent->getId(),
            'tool_id' => (string) $tool->getId(),
            'enabled' => true,
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.setNervousSystemAgentTool.is_active', true)
            ->assertJsonPath('data.setNervousSystemAgentTool.is_deleted', false)
            ->assertJsonPath('data.setNervousSystemAgentTool.tool.id', (string) $tool->getId());

        $this->assertDatabaseHas('nervous_system_agent_tools', [
            'agent_id' => $agent->getId(),
            'tool_id' => $tool->getId(),
            'is_active' => 1,
            'is_deleted' => 0,
        ], 'intelligence');
    }

    public function testEnabledFalseRevokesAnExistingGrant(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeTool();

        // First, grant.
        $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: true) {
                    is_active
                }
            }
        ', ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()])->assertSuccessful();

        // Now revoke.
        $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: false) {
                    is_active
                    is_deleted
                }
            }
        ', ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.setNervousSystemAgentTool.is_active', false)
            ->assertJsonPath('data.setNervousSystemAgentTool.is_deleted', true);
    }

    public function testEnabledTrueReactivatesAPreviouslyRevokedGrant(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeTool();
        $variables = ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()];

        // Grant → revoke → grant again. Result must be one row, active, not deleted.
        foreach ([true, false, true] as $enabled) {
            $this->graphQL('
                mutation($agent_id: ID!, $tool_id: ID!, $enabled: Boolean!) {
                    setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: $enabled) {
                        is_active
                    }
                }
            ', $variables + ['enabled' => $enabled])->assertSuccessful();
        }

        $rows = AgentTool::query()
            ->where('agent_id', $agent->getId())
            ->where('tool_id', $tool->getId())
            ->get();
        $this->assertCount(1, $rows, 'Toggle must update in place, never duplicate the grant row');
        $this->assertTrue($rows->first()->is_active);
        $this->assertFalse($rows->first()->is_deleted);
    }

    public function testGrantingAToolFromADifferentAppReturnsClientSafeError(): void
    {
        $agent = $this->makeAgent();

        // Create a tool that does NOT belong to the current app (use a high
        // bogus apps_id that won't match the user's app).
        $foreignTool = DB::connection('intelligence')->table('nervous_system_tools')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 999999,
            'name' => 'foreign-tool-' . uniqid(),
            'tool_type' => 'custom',
            'frameworks' => json_encode(['neuron']),
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: true) {
                    id
                }
            }
        ', ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $foreignTool]);

        $errors = $response->json('errors');
        $this->assertNotNull($errors, 'Expected a GraphQL error, got data instead');
        $this->assertStringContainsString(
            'not available in this app',
            (string) ($errors[0]['message'] ?? ''),
            'Error message must be client-safe and explain the issue — not "Internal server error"',
        );
    }

    public function testEnabledFalseOnNeverGrantedToolIsANoOp(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeTool();

        $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: false) {
                    is_active
                    is_deleted
                }
            }
        ', ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.setNervousSystemAgentTool.is_active', false)
            ->assertJsonPath('data.setNervousSystemAgentTool.is_deleted', true);
    }

    public function testEnabledTrueWritesToSelectedToolsPivot(): void
    {
        // `nervous_system_agent_selected_tools` is the runtime source of truth:
        // CapabilityProvider::getActiveTools reads only from this pivot.
        // setNervousSystemAgentTool MUST sync into it or the runtime can never
        // pick up the tool, even though the AgentTool grant row exists.
        $agent = $this->makeAgent();
        $tool = $this->makeTool();

        $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: true) {
                    is_active
                }
            }
        ', ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()])
            ->assertSuccessful();

        $this->assertDatabaseHas(
            'nervous_system_agent_selected_tools',
            ['agent_id' => $agent->getId(), 'tool_id' => $tool->getId()],
            'intelligence',
        );
    }

    public function testGrantToolWithSubAgentHandlerDoesNotCrash(): void
    {
        // DynamicSubAgent (the handler used for sub-agent tools created by
        // CreateAgentAction::ensureSubAgentTool) requires an AgentRecord in
        // its constructor. AppendToolInstructionsAction historically did
        // `new $tool->handler()` and crashed with ArgumentCountError, which
        // killed the whole setNervousSystemAgentTool mutation. This test
        // pins the resilience — the mutation must succeed even when one of
        // the agent's selected tools has a handler that can't be naively
        // instantiated.
        $agent = $this->makeAgent();
        $tool = $this->makeTool();
        $tool->handler = 'Kanvas\\Intelligence\\Agents\\Laravel\\SubAgents\\DynamicSubAgent';
        $tool->saveOrFail();

        $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: true) {
                    is_active
                }
            }
        ', ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.setNervousSystemAgentTool.is_active', true);

        $this->assertDatabaseHas(
            'nervous_system_agent_selected_tools',
            ['agent_id' => $agent->getId(), 'tool_id' => $tool->getId()],
            'intelligence',
        );
    }

    public function testToggleSurvivesLegacyDuplicateRows(): void
    {
        // Simulate the production data state seen in Sentry: a soft-deleted
        // ghost row + an active row for the same (agent_id, tool_id) pair,
        // both legal under the (agent_id, tool_id, is_deleted) unique key.
        // Without sibling cleanup, revoking the active row collides with the
        // ghost (1062 Duplicate entry on agent_tool_unique). The mutation
        // must reconcile this on the fly.
        $agent = $this->makeAgent();
        $tool = $this->makeTool();

        DB::connection('intelligence')->table('nervous_system_agent_tools')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'apps_id' => $agent->apps_id,
                'companies_id' => $agent->companies_id,
                'agent_id' => $agent->getId(),
                'tool_id' => $tool->getId(),
                'granted_by_users_id' => $agent->user_id,
                'granted_at' => now()->subMinutes(10),
                'is_active' => 0,
                'is_deleted' => 1,
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'apps_id' => $agent->apps_id,
                'companies_id' => $agent->companies_id,
                'agent_id' => $agent->getId(),
                'tool_id' => $tool->getId(),
                'granted_by_users_id' => $agent->user_id,
                'granted_at' => now()->subMinutes(5),
                'is_active' => 1,
                'is_deleted' => 0,
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ],
        ]);

        $this->assertSame(
            2,
            AgentTool::query()
                ->withTrashed()
                ->where('agent_id', $agent->getId())
                ->where('tool_id', $tool->getId())
                ->count(),
            'Setup precondition: two legacy rows must exist before the mutation runs.',
        );

        $this->graphQL('
            mutation($agent_id: ID!, $tool_id: ID!) {
                setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: false) {
                    id
                    is_deleted
                }
            }
        ', ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.setNervousSystemAgentTool.is_deleted', true);

        $this->assertSame(
            1,
            AgentTool::query()
                ->withTrashed()
                ->where('agent_id', $agent->getId())
                ->where('tool_id', $tool->getId())
                ->count(),
            'After the revoke, only one row must remain — the ghost sibling is hard-deleted.',
        );
    }

    public function testToggleOffThenOnReturnsAgentToOnState(): void
    {
        // Reproduces the production duplicate-row bug: without withTrashed on
        // the existence lookup, toggling off then on creates a SECOND grant row
        // instead of reactivating the first; the read query then sees both
        // rows and array_diff drops the tool. The pivot also drifts.
        $agent = $this->makeAgent();
        $tool = $this->makeTool();
        $vars = ['agent_id' => (string) $agent->getId(), 'tool_id' => (string) $tool->getId()];

        foreach ([true, false, true] as $enabled) {
            $this->graphQL('
                mutation($agent_id: ID!, $tool_id: ID!, $enabled: Boolean!) {
                    setNervousSystemAgentTool(agent_id: $agent_id, tool_id: $tool_id, enabled: $enabled) {
                        is_active
                    }
                }
            ', $vars + ['enabled' => $enabled])->assertSuccessful();
        }

        $this->assertSame(
            1,
            AgentTool::query()
                ->withTrashed()
                ->where('agent_id', $agent->getId())
                ->where('tool_id', $tool->getId())
                ->count(),
            'Toggle cycles must reactivate the existing row, never produce duplicates.',
        );

        $finalRow = AgentTool::query()
            ->withTrashed()
            ->where('agent_id', $agent->getId())
            ->where('tool_id', $tool->getId())
            ->first();
        $this->assertTrue($finalRow->is_active, 'After grant→revoke→grant the row must be active again.');
        $this->assertFalse($finalRow->is_deleted, 'After grant→revoke→grant the row must not be soft-deleted.');

        $this->assertDatabaseHas(
            'nervous_system_agent_selected_tools',
            ['agent_id' => $agent->getId(), 'tool_id' => $tool->getId()],
            'intelligence',
            'After re-grant the pivot must contain the tool — runtime reads from this table.',
        );

        // And the agentTools read query must include it — not get killed by a
        // stale revoked row in array_diff.
        $visible = $this->graphQL('
            query($agent_id: ID!) {
                nervousSystemAgentTools(agent_id: $agent_id) { id }
            }
        ', ['agent_id' => (string) $agent->getId()])
            ->assertSuccessful()
            ->json('data.nervousSystemAgentTools');
        $this->assertContains(
            (string) $tool->getId(),
            array_column($visible, 'id'),
            'After re-grant the tool must appear in nervousSystemAgentTools.',
        );
    }
}
