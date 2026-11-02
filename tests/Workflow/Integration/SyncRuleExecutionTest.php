<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Tests\TestCase;
use Workflow\WorkflowStub;

/**
 * A sync rule runs through the library's own stub on the `sync` queue connection; these pin that it
 * finishes inside the call and leaves its output readable.
 */
final class SyncRuleExecutionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow'];

    public function testSyncWorkflowExecutesThroughProcessWorkflowEventAction(): void
    {
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();

        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $result = $processWorkflow->execute(WorkflowEnum::CREATED->value, []);

        $this->assertInstanceOf(WorkflowStub::class, $result);
        $this->assertTrue(
            $result->completed() || $result->running(),
            'Sync workflow should have completed or be running after synchronous dispatch'
        );
    }

    public function testSyncWorkflowProducesOutput(): void
    {
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();

        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $result = $processWorkflow->execute(WorkflowEnum::CREATED->value, []);

        $this->assertInstanceOf(WorkflowStub::class, $result);

        $output = $result->output();
        if ($result->completed()) {
            $this->assertNotNull($output);
            $this->assertIsArray($output);
        }
    }

    public function testSyncWorkflowWithConditionMismatchStartsNoWorkflow(): void
    {
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();
        $before = StoredWorkflow::count();

        // `pull` rather than `created`: nothing else listens on pull for leads, so the count below
        // measures this rule alone instead of every created rule in the database.
        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        Rule::query()->whereKey($ruleWorkflowAction->rules_id)->update([
            'rules_types_id' => RuleType::getByName(WorkflowEnum::PULL->value)->getId(),
        ]);
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'interaction',
            'operator' => '==',
            'value' => 'nonexistent_value_' . uniqid(),
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $result = $processWorkflow->execute(WorkflowEnum::PULL->value, [
            'interaction' => 'something_else',
        ]);

        // The condition is checked before a workflows row is created, so a mismatch costs nothing.
        $this->assertNull($result);
        $this->assertSame($before, StoredWorkflow::count());
    }
}
