<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class RuleActionFactory extends Factory
{
    protected $model = RuleAction::class;

    public function definition()
    {
        return [
           'rules_id' => Rule::factory()->create()->getId(),
           'rules_workflow_actions_id' => RuleWorkflowAction::factory()->create()->getId(),
           'weight' => 0,
        ];
    }
}
