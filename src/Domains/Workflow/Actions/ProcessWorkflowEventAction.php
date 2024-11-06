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

    public function execute(string $event, array $params = []): void
    {
        try {
            $ruleType = RuleType::getByName($event);
        } catch (ModelNotFoundException $e) {
            return;
        }

        $company = isset($params['company']) && $params['company'] instanceof CompanyInterface ? $params['company'] : null;
        $rules = RuleRepository::getRulesByModelAndType($this->app, $this->entity, $ruleType, $company);
        if ($rules->count() > 0) {
            foreach ($rules as $rule) {
                if ($rule->runAsync()) {
                    $workflow = WorkflowStub::make(DynamicRuleWorkflow::class);
                } else {
                    $workflow = SyncWorkflowStub::make(DynamicRuleWorkflow::class);
                }
                $workflow->start($this->app, $rule, $this->entity, $params);
            }
        }
    }
}
