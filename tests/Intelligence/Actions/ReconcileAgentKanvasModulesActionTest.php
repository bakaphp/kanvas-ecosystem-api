<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\ReconcileAgentKanvasModulesAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentKanvasModule;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

class ReconcileAgentKanvasModulesActionTest extends TestCase
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

    private function makeToolWithModules(array $moduleEnums): Tool
    {
        $tool = new CreateToolAction(
            new ToolData(
                app: app(Apps::class),
                name: 'reconcile-test-tool-' . uniqid(),
                frameworks: ['neuron'],
                toolType: ToolTypeEnum::CUSTOM,
            ),
        )->execute();

        foreach ($moduleEnums as $enum) {
            DB::connection('intelligence')->table('nervous_system_tool_kanvas_modules')->insert([
                'tool_id' => $tool->getId(),
                'kanvas_modules_id' => $enum->value,
                'direction' => 'consumes',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $tool;
    }

    private function attachTool(Agent $agent, Tool $tool): void
    {
        DB::connection('intelligence')->table('nervous_system_agent_selected_tools')->insert([
            'agent_id' => $agent->getId(),
            'tool_id' => $tool->getId(),
        ]);
    }

    private function detachTool(Agent $agent, Tool $tool): void
    {
        DB::connection('intelligence')->table('nervous_system_agent_selected_tools')
            ->where('agent_id', $agent->getId())
            ->where('tool_id', $tool->getId())
            ->delete();
    }

    public function testReconcileAddsSubscriptionForEveryModuleATooltouches(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeToolWithModules([KanvasModuleEnum::CRM, KanvasModuleEnum::INVENTORY]);
        $this->attachTool($agent, $tool);

        $result = new ReconcileAgentKanvasModulesAction($agent)->execute();

        $this->assertSame(2, $result['added']);
        $this->assertSame(0, $result['kept']);
        $this->assertSame(0, $result['deactivated']);

        $subs = AgentKanvasModule::query()
            ->where('agent_id', $agent->getId())
            ->where('is_active', 1)
            ->pluck('kanvas_modules_id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [KanvasModuleEnum::CRM->value, KanvasModuleEnum::INVENTORY->value],
            array_map('intval', $subs),
        );
    }

    public function testReconcileIsIdempotent(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeToolWithModules([KanvasModuleEnum::CRM]);
        $this->attachTool($agent, $tool);

        new ReconcileAgentKanvasModulesAction($agent)->execute();
        $second = new ReconcileAgentKanvasModulesAction($agent)->execute();

        $this->assertSame(0, $second['added']);
        $this->assertSame(1, $second['kept']);
        $this->assertSame(
            1,
            AgentKanvasModule::query()->where('agent_id', $agent->getId())->count(),
        );
    }

    public function testDetachingToolDeactivatesSubscriptionWithoutDeleting(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeToolWithModules([KanvasModuleEnum::CRM]);
        $this->attachTool($agent, $tool);
        new ReconcileAgentKanvasModulesAction($agent)->execute();

        $this->detachTool($agent, $tool);
        $result = new ReconcileAgentKanvasModulesAction($agent)->execute();

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['deactivated']);

        $row = AgentKanvasModule::query()
            ->where('agent_id', $agent->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::CRM->value)
            ->first();

        $this->assertNotNull($row, 'Row must NOT be hard-deleted on tool removal — config could be lost');
        $this->assertFalse($row->is_active);
        $this->assertFalse($row->is_deleted);
    }

    public function testReattachingToolReactivatesExistingSubscription(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeToolWithModules([KanvasModuleEnum::CRM]);
        $this->attachTool($agent, $tool);
        new ReconcileAgentKanvasModulesAction($agent)->execute();

        // Manually set a config value, then detach + reconcile to deactivate.
        AgentKanvasModule::query()
            ->where('agent_id', $agent->getId())
            ->update(['config' => ['pipelines' => [7]]]);
        $this->detachTool($agent, $tool);
        new ReconcileAgentKanvasModulesAction($agent)->execute();

        // Re-attach + reconcile — row should reactivate, config preserved.
        $this->attachTool($agent, $tool);
        $result = new ReconcileAgentKanvasModulesAction($agent)->execute();

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['kept']);

        $row = AgentKanvasModule::query()
            ->where('agent_id', $agent->getId())
            ->where('kanvas_modules_id', KanvasModuleEnum::CRM->value)
            ->first();

        $this->assertTrue($row->is_active);
        $this->assertSame(['pipelines' => [7]], $row->config);
    }

    public function testToolWithNoDeclaredModulesProducesNoSubscriptions(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeToolWithModules([]);
        $this->attachTool($agent, $tool);

        $result = new ReconcileAgentKanvasModulesAction($agent)->execute();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['kept']);
        $this->assertSame(0, AgentKanvasModule::query()->where('agent_id', $agent->getId())->count());
    }

    public function testRowsAreTenantStampedFromTheAgent(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->makeToolWithModules([KanvasModuleEnum::CRM]);
        $this->attachTool($agent, $tool);

        new ReconcileAgentKanvasModulesAction($agent)->execute();

        $row = AgentKanvasModule::query()
            ->where('agent_id', $agent->getId())
            ->firstOrFail();

        $this->assertSame($agent->apps_id, $row->apps_id);
        $this->assertSame($agent->companies_id, $row->companies_id);
    }
}
