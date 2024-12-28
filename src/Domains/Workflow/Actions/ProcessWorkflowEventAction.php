<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Repositories\RuleRepository;
use Kanvas\Workflow\SyncWorkflowStub;
use Workflow\WorkflowStub;

class ProcessWorkflowEventAction
{
    public function __construct(
        protected AppInterface $app,
        protected Model $entity,
    ) {
    }

    public function execute(string $event, array $params = []): ?SyncWorkflowStub
    {
        try {
            $ruleType = RuleType::getByName($event);
        } catch (ModelNotFoundException) {
            return null;
        }

        $company = $params['company'] ?? null;
        if ($company && ! $company instanceof CompanyInterface) {
            $company = null;
        }

        $rules = RuleRepository::getRulesByModelAndType(
            $this->app,
            $this->entity,
            $ruleType,
            $company
        );

        if ($rules->isEmpty()) {
            return null;
        }

        $lastSyncWorkflow = null;

        $rules->each(function ($rule) use (&$lastSyncWorkflow, $params) {
            $workflow = $rule->runAsync()
                ? WorkflowStub::make(DynamicRuleWorkflow::class)
                : SyncWorkflowStub::make(DynamicRuleWorkflow::class);

            $workflow->start($this->app, $rule, $this->entity, $params);

            if (! $rule->runAsync()) {
                $lastSyncWorkflow = $workflow;
            }
        });

        return $lastSyncWorkflow;
    }
}
