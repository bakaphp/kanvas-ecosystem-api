<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules;

use Baka\Contracts\AppInterface;
use Generator;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Throwable;
use Workflow\ActivityStub;
use Workflow\Workflow;

class DynamicRuleWorkflow extends Workflow
{
    /**
     * @param object|Model $entity
     */
    public function execute(AppInterface $app, Rule $rule, object $entity, array $params): Generator
    {
        if (! $entity instanceof Model) {
            throw new InvalidArgumentException('Entity must be a Model');
        }

        $activities = [];

        list('expression' => $expression, 'values' => $values) = $rule->getExpressionCondition();

        $values = array_merge(
            $values,
            $entity->toArray(), // For direct attribute access
            [
                'entity' => $entity, // Full entity object
                strtolower(class_basename($entity)) => $entity, // Named access (e.g., 'order')
            ],
            $params
        );

        $expressionLanguage = new ExpressionLanguage();

        //validate the expression and values with symfony expression language
        try {
            $result = $expressionLanguage->evaluate(
                $expression,
                $values
            );
        } catch (Throwable $e) {
            report($e);

            return $activities;
        }

        if (! $result) {
            return $activities;
        }

        if (is_array($rule->params) && count($rule->params) > 0) {
            $params = array_merge($params, $rule->params);
        }

        unset($params['app']); //don't pass the app to the activity via the param array
        $params['rule'] = $rule;

        foreach ($rule->workflowActivities as $workflowActivity) {
            $activity = $workflowActivity->activity;

            if ($rule->runAsync()) {
                $activities[] = yield ActivityStub::make($activity->actionClass(), $entity, $app, $params);
            } else {
                $activityClass = $activity->actionClass();
                $activity = new $activityClass(
                    index: 0,
                    now: now()->toDateTimeString(),
                    storedWorkflow: new StoredWorkflow(),
                    arguments: []
                );

                $activities[] = $activity->execute($entity, $app, $params);
            }
        }

        return $activities;
    }
}
