<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules;

use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Support\ExpressionData;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Throwable;
use Workflow\ActivityStub;
use Workflow\Workflow;

class DynamicRuleWorkflow extends Workflow
{
    /**
     * @param object|Model $entity
     */
    public function execute(Apps $app, Rule $rule, object $entity, array $params): Generator
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

        // JSON columns arrive as plain arrays; wrap them so a rule can navigate nested keys with
        // dot syntax (metadata.data.x) — Symfony's `.` operator only works on objects, not arrays.
        $values = ExpressionData::wrapValues($values);

        $expressionLanguage = new ExpressionLanguage();

        //validate the expression and values with symfony expression language
        try {
            $result = $expressionLanguage->evaluate(
                $expression,
                $values
            );
        } catch (Throwable $e) {
            Log::debug('Rule expression evaluation failed; treating as non-match', [
                'rule_id' => $rule->getId(),
                'expression' => $expression,
                'entity' => $entity::class,
                'entity_id' => $entity->getKey(),
                'error' => $e->getMessage(),
            ]);

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
