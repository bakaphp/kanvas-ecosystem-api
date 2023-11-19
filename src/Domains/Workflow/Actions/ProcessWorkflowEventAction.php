<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Repositories\RuleRepository;
use Workflow\WorkflowStub;

class ProcessWorkflowEventAction
{
    public function __construct(
        protected AppInterface $app,
        protected Model $model,
    ) {
    }

    public function execute(string $event, array $params = []): void
    {
        try {
            $ruleType = RuleType::getByName($event);
        } catch (ModelNotFoundException $e) {
            return;
        }

        $rules = RuleRepository::getRulesByModelAndType($this->app, $this->model, $ruleType);

        if ($rules->count() > 0) {
            foreach ($rules as $rule) {
                $workflow = WorkflowStub::make(DynamicRuleWorkflow::class);
                $workflow->start($rule, $this->model, $params);
            }
        }
    }
}
