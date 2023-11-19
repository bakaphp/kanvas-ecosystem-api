<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Rules\Models\Rule;
use Workflow\ActivityStub;
use Workflow\Workflow;

class DynamicRuleWorkflow extends Workflow
{
    public function execute(Rule $rule, Model $entity, array $params)
    {
        $activities = [];
        foreach ($rule->workflowActivities as $workflowActivity) {
            $activity = $workflowActivity->activity;
            $activities[] = yield ActivityStub::make($activity->actionClass(), $entity, $params);
        }

        return $activities;
    }
}
