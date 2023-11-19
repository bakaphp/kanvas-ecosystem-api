<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\Enums\RuleTypeEnum;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Tests\TestCase;

final class DynamicWorkflowTest extends TestCase
{
    public function testDynamicWorkflow(): void
    {
        $app = app(Apps::class);
        $lead = new Lead();
        $params = [];

        $ruleWorkflowAction = RuleAction::factory()->create();
        RuleCondition::factory()->create([
            'rules_id' => $ruleWorkflowAction->rules_id,
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);
        
        die('33');
        $processWorkflow = new ProcessWorkflowEventAction($app, $lead);
        $processWorkflow->execute(RuleTypeEnum::CREATED->value, $params);
    }
}
