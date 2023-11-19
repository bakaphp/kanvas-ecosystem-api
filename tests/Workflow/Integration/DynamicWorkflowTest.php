<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Workflows\ZohoLeadWorkflow;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\RuleTypeEnum;
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
        $processWorkflow->execute(RuleTypeEnum::CREATED->value, $params);

        
        $this->assertEquals($totalWorkflows + Rule::count(), StoredWorkflow::count());
    }
}
