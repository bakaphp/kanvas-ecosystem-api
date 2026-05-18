<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Capability;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\Actions\ReconcileToolKanvasModulesAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\Stubs\Intelligence\FakeCrmInventoryToolHandler;
use Tests\Stubs\Intelligence\FakeCrmOnlyToolHandler;
use Tests\Stubs\Intelligence\FakeToolHandlerWithoutContract;
use Tests\TestCase;

class ReconcileToolKanvasModulesActionTest extends TestCase
{
    private function makeTool(?string $handler): Tool
    {
        return new CreateToolAction(
            new ToolData(
                app: app(Apps::class),
                name: 'tool-reconcile-' . uniqid(),
                frameworks: ['neuron'],
                toolType: ToolTypeEnum::CUSTOM,
                handler: $handler,
            ),
        )->execute();
    }

    private function pivotRows(Tool $tool): array
    {
        return DB::connection('intelligence')
            ->table('nervous_system_tool_kanvas_modules')
            ->where('tool_id', $tool->getId())
            ->get()
            ->map(fn ($r) => ['module' => (int) $r->kanvas_modules_id, 'direction' => $r->direction])
            ->sortBy('module')
            ->values()
            ->all();
    }

    public function testReconcileInsertsRowsForEveryDeclaredModule(): void
    {
        $tool = $this->makeTool(FakeCrmInventoryToolHandler::class);

        $result = new ReconcileToolKanvasModulesAction($tool)->execute();

        $this->assertSame(2, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['removed']);

        $this->assertEquals(
            [
                ['module' => KanvasModuleEnum::INVENTORY->value, 'direction' => 'both'],
                ['module' => KanvasModuleEnum::CRM->value, 'direction' => 'consumes'],
            ],
            // sort by module id ascending — Inventory (2) before CRM (3)
            $this->pivotRows($tool),
        );
    }

    public function testReconcileIsIdempotent(): void
    {
        $tool = $this->makeTool(FakeCrmOnlyToolHandler::class);

        new ReconcileToolKanvasModulesAction($tool)->execute();
        $second = new ReconcileToolKanvasModulesAction($tool)->execute();

        $this->assertSame(0, $second['added']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, $second['removed']);
        $this->assertCount(1, $this->pivotRows($tool));
    }

    public function testReconcileRemovesRowsTheHandlerNoLongerDeclares(): void
    {
        $tool = $this->makeTool(FakeCrmInventoryToolHandler::class);
        new ReconcileToolKanvasModulesAction($tool)->execute();
        $this->assertCount(2, $this->pivotRows($tool));

        // Swap to a handler that only declares CRM.
        $tool->handler = FakeCrmOnlyToolHandler::class;
        $tool->save();

        $result = new ReconcileToolKanvasModulesAction($tool)->execute();

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['removed']);
        $rows = $this->pivotRows($tool);
        $this->assertCount(1, $rows);
        $this->assertSame(KanvasModuleEnum::CRM->value, $rows[0]['module']);
    }

    public function testToolWithoutAHandlerIsNoOp(): void
    {
        $tool = $this->makeTool(null);

        $result = new ReconcileToolKanvasModulesAction($tool)->execute();

        $this->assertSame(['added' => 0, 'updated' => 0, 'removed' => 0], $result);
        $this->assertCount(0, $this->pivotRows($tool));
    }

    public function testHandlerThatDoesNotImplementContractIsNoOp(): void
    {
        $tool = $this->makeTool(FakeToolHandlerWithoutContract::class);

        $result = new ReconcileToolKanvasModulesAction($tool)->execute();

        $this->assertSame(['added' => 0, 'updated' => 0, 'removed' => 0], $result);
        $this->assertCount(0, $this->pivotRows($tool));
    }

    public function testHandlerWithUnknownClassIsNoOp(): void
    {
        $tool = $this->makeTool('App\\Doesnt\\Exist');

        $result = new ReconcileToolKanvasModulesAction($tool)->execute();

        $this->assertSame(['added' => 0, 'updated' => 0, 'removed' => 0], $result);
    }
}
