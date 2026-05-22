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

        // No DB row created for a no-op revoke.
        $this->assertSame(
            0,
            AgentTool::query()->where('agent_id', $agent->getId())->where('tool_id', $tool->getId())->count(),
        );
    }
}
