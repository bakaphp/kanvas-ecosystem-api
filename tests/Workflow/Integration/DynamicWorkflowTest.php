<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Tests\TestCase;
use Workflow\WorkflowStub;

final class DynamicWorkflowTest extends TestCase
{
    public function testDynamicWorkflow(): void
    {
        WorkflowStub::fake();

        $totalWorkflows = StoredWorkflow::count();
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();
        $params = [];

        $ruleWorkflowAction = RuleAction::factory()->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $processWorkflow->execute(WorkflowEnum::CREATED->value, $params);

        $this->assertEquals($totalWorkflows + Rule::count(), StoredWorkflow::count());
    }

    public function testDynamicWorkflowUsingParams(): void
    {
        WorkflowStub::fake();

        $totalWorkflows = StoredWorkflow::count();
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();
        $params = [
            'interaction' => 'like',
        ];

        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'interaction',
            'operator' => '==',
            'value' => 'likes',
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $results = $processWorkflow->execute(WorkflowEnum::CREATED->value, $params);

        //since value and param interaction don't match we will get a empty result
        $this->assertEmpty($results->output());

        $params = [
            'interaction' => 'likes',
        ];
        $results = $processWorkflow->execute(WorkflowEnum::CREATED->value, $params);

        $this->assertArrayHasKey('status', $results->output()[0]);
    }

    public function testSyncDynamicWorkflow(): void
    {
        $totalWorkflows = StoredWorkflow::count();
        $app = app(Apps::class);
        $lead = Lead::count() > 0 ? Lead::first() : Lead::factory()->create();
        $params = [];

        $ruleWorkflowAction = RuleAction::factory()->withAsync(false)->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $processWorkflow->execute(WorkflowEnum::CREATED->value, $params);

        $this->assertEquals($totalWorkflows + Rule::count(), StoredWorkflow::count());
    }
}
