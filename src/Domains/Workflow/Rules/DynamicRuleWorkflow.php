<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules;

use Generator;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Support\TransientEntity;
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

        if ($entity instanceof Message) {
            $messageData = (array) ($entity->message['data'] ?? []);
            $params = array_merge($params, (array) ($messageData['params'] ?? []));

            if (isset($messageData['pdf_file_name'])) {
                $params['pdf_file_name'] = $messageData['pdf_file_name'];
            }
        }

        unset($params['app']); //don't pass the app to the activity via the param array
        $params['rule'] = $rule;

        foreach ($rule->workflowActivities as $workflowActivity) {
            $activity = $workflowActivity->activity;

            if ($rule->runAsync()) {
                $activities[] = yield ActivityStub::make($activity->actionClass(), TransientEntity::wrap($entity), $app, $params);
            } else {
                // The real row, so the activity's workflowId() and its history rows join back to this run.
                $activityClass = $activity->actionClass();
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
}
