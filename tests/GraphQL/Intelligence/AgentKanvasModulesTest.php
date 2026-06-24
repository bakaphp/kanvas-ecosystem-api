<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentKanvasModule;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Tests\TestCase;

class AgentKanvasModulesTest extends TestCase
{
    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ];
    }

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

    private function seedSubscription(Agent $agent, KanvasModuleEnum $module, ?array $config = null): AgentKanvasModule
    {
        return AgentKanvasModule::create([
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_id' => $agent->getId(),
            'kanvas_modules_id' => $module->value,
            'config' => $config,
            'is_active' => true,
            'is_deleted' => false,
        ]);
    }

    public function testAgentKanvasModulesQueryReturnsSubscriptions(): void
    {
        $agent = $this->makeAgent();
        $this->seedSubscription($agent, KanvasModuleEnum::CRM, ['pipelines' => [7]]);

        $response = $this->graphQL('
            query($id: Mixed!) {
                agentsAi(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        kanvasModules {
                            is_active
                            config
                            module { id name }
                        }
                    }
                }
            }
        ', ['id' => $agent->getId()])->assertSuccessful();

        $modules = $response->json('data.agentsAi.data.0.kanvasModules');
        $this->assertNotNull($modules, 'Agent did not resolve in agentsAi list');
        $this->assertCount(1, $modules);
        $this->assertTrue($modules[0]['is_active']);
        $this->assertSame(['pipelines' => [7]], $modules[0]['config']);
        $this->assertSame((string) KanvasModuleEnum::CRM->value, $modules[0]['module']['id']);
    }

    public function testSetAgentKanvasModuleUpsertsTheRow(): void
    {
        $agent = $this->makeAgent();

        $input = [
            'agent_id' => (string) $agent->getId(),
            'kanvas_module_id' => (string) KanvasModuleEnum::INVENTORY->value,
            'config' => ['warehouses' => [12]],
            'is_active' => true,
        ];

        $this->graphQL('
            mutation($agent_id: ID!, $kanvas_module_id: ID!, $config: Mixed, $is_active: Boolean) {
                setAgentKanvasModule(
                    agent_id: $agent_id
                    kanvas_module_id: $kanvas_module_id
                    config: $config
                    is_active: $is_active
                ) {
                    is_active
                    config
                    module { id }
                }
            }
        ', $input, [], $this->getAppKeyHeader())
            ->assertSuccessful()
            ->assertJsonPath('data.setAgentKanvasModule.is_active', true)
            ->assertJsonPath('data.setAgentKanvasModule.config', ['warehouses' => [12]])
            ->assertJsonPath(
                'data.setAgentKanvasModule.module.id',
                (string) KanvasModuleEnum::INVENTORY->value,
            );

        // Calling it again with new config UPDATES (not duplicates).
        $this->graphQL('
            mutation($agent_id: ID!, $kanvas_module_id: ID!, $config: Mixed) {
                setAgentKanvasModule(agent_id: $agent_id, kanvas_module_id: $kanvas_module_id, config: $config) {
                    config
                }
            }
        ', [
            'agent_id' => $input['agent_id'],
            'kanvas_module_id' => $input['kanvas_module_id'],
            'config' => ['warehouses' => [99]],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $rows = DB::connection('intelligence')->table('agents_kanvas_modules')
            ->where('agent_id', $agent->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::INVENTORY->value)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['warehouses' => [99]], json_decode($rows->first()->config, true));
    }

    public function testRemoveAgentKanvasModuleFlipsIsDeleted(): void
    {
        $agent = $this->makeAgent();
        $this->seedSubscription($agent, KanvasModuleEnum::CRM);

        $this->graphQL('
            mutation($agent_id: ID!, $kanvas_module_id: ID!) {
                removeAgentKanvasModule(agent_id: $agent_id, kanvas_module_id: $kanvas_module_id)
            }
        ', [
            'agent_id' => (string) $agent->getId(),
            'kanvas_module_id' => (string) KanvasModuleEnum::CRM->value,
        ], [], $this->getAppKeyHeader())
            ->assertSuccessful()
            ->assertJsonPath('data.removeAgentKanvasModule', true);

        // SoftDeletesTrait global scope hides is_deleted=1 rows — check raw DB.
        $row = DB::connection('intelligence')->table('agents_kanvas_modules')
            ->where('agent_id', $agent->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::CRM->value)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->is_deleted);
        $this->assertSame(0, (int) $row->is_active);
    }

    public function testSetAgentKanvasModuleRejectsRequestWithoutAppKey(): void
    {
        $agent = $this->makeAgent();

        $response = $this->graphQL('
            mutation($agent_id: ID!, $kanvas_module_id: ID!) {
                setAgentKanvasModule(agent_id: $agent_id, kanvas_module_id: $kanvas_module_id) {
                    is_active
                }
            }
        ', [
            'agent_id' => (string) $agent->getId(),
            'kanvas_module_id' => (string) KanvasModuleEnum::CRM->value,
        ]);

        $response->assertGraphQLErrorMessage('Unauthenticated.');
        $this->assertSame(
            0,
            AgentKanvasModule::query()->where('agent_id', $agent->getId())->count(),
            'No row should have been created when the request is unauthenticated.',
        );
    }
}
