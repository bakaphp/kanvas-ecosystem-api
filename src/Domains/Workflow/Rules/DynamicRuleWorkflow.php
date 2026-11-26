<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules;

use Baka\Support\AllowedClass;
use Generator;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Support\TransientEntity;
use Override;
use Workflow\Activity;
use Workflow\ActivityStub;
use Workflow\Workflow;

class DynamicRuleWorkflow extends Workflow
{
    /**
     * @param object|Model $entity
     */
    public function execute(Apps $app, Rule $rule, object $entity, array $params): Generator
    {
        $entity = TransientEntity::unwrap($entity);

        if (! $entity instanceof Model) {
            throw new InvalidArgumentException('Entity must be a Model');
        }

        $activities = [];

        if (! $rule->matches($entity, $params)) {
            return $activities;
        }

        if (is_array($rule->params) && count($rule->params) > 0) {
            $params = array_merge($params, $rule->params);
        }

        unset($params['app']); //don't pass the app to the activity via the param array
        $params['rule'] = $rule;

        foreach ($rule->workflowActivities as $workflowActivity) {
            $activityClass = AllowedClass::resolve(
                $workflowActivity->activity?->action?->model_name,
                Activity::class
            );

            // An activity removed from the code is still named by the rule of a company not yet
            // moved off it, and building a class that is gone is fatal to the whole run.
            if ($activityClass === null) {
                continue;
            }

            if ($rule->runAsync()) {
                $activities[] = yield ActivityStub::make($activityClass, TransientEntity::wrap($entity), $app, $params);
            } else {
                // The real row, so the activity's workflowId() and its history rows join back to this run.
                $activity = new $activityClass(
                    index: 0,
                    now: now()->toDateTimeString(),
                    storedWorkflow: $this->storedWorkflow,
                    arguments: []
                );

                $activities[] = $activity->executeRecorded($entity, $app, $params);
            }
        }

        return $activities;
    }

    /**
     * The engine releases a blocked workflow job with no delay and no cap, from the overlap middleware
     * and from handle(); a blocker that never clears is ~30 releases a second per job. Not
     * `overlapReleaseAfter`: that also delays every activity racing its own workflow's unlock.
     */
    #[Override]
    public function release($delay = 0)
    {
        parent::release(
            is_int($delay) ? max($delay, self::releaseDelayFor($this->attempts())) : $delay
        );
    }

    public static function releaseDelayFor(int $attempts): int
    {
        return match (true) {
            $attempts <= 25 => 0,
            $attempts <= 200 => 5,
            default => 60,
        };
    }
}
