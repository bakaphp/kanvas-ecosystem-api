<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Repositories\RuleRepository;
use Kanvas\Workflow\Support\TransientEntity;
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

class ProcessWorkflowEventAction
{
    /** @var list<int> */
    private array $startedWorkflowIds = [];

    public function __construct(
        protected Apps $app,
        protected Model $entity,
    ) {
    }

    public function execute(string $event, array $params = []): ?WorkflowStub
    {
        $params['currentEventTypeName'] = strtolower($event);
        $this->startedWorkflowIds = [];

        // Matched here, before a `workflows` row exists for the rule: most events match nothing,
        // and a row per non-match is what filled the table.
        $rules = $this->matchingRules($event, $params);

        if ($rules->isEmpty()) {
            return null;
        }

        $lastSyncWorkflow = null;
        $subject = TransientEntity::wrap($this->entity);

        $rules->each(function (Rule $rule) use (&$lastSyncWorkflow, $params, $subject) {
            $workflow = WorkflowStub::make(DynamicRuleWorkflow::class);

            // The `sync` queue driver runs the job inside push(), so a sync rule finishes before
            // start() returns and its output is readable by the caller.
            if ($rule->runAsync()) {
                $workflow->start(
                    $this->app,
                    $rule,
                    $subject,
                    $params
                );
            } else {
                $workflow->start(
                    new WorkflowOptions(connection: 'sync'),
                    $this->app,
                    $rule,
                    $subject,
                    $params
                );
                $lastSyncWorkflow = $workflow;
            }

            $this->startedWorkflowIds[] = (int) $workflow->id();
        });

        return $lastSyncWorkflow;
    }

    /**
     * @return list<int> ids of the `workflows` rows the last execute() started, sync and async alike
     */
    public function startedWorkflowIds(): array
    {
        return $this->startedWorkflowIds;
    }

    /**
     * Whether firing `$event` would run anything. `execute()` returns null both when nothing matches
     * and when every matching rule is async, so a caller that needs a legacy fallback only in the
     * first case has to ask this instead. A rule that exists but does not match counts as none.
     */
    public function hasRules(string $event, array $params = []): bool
    {
        return $this->matchingRules($event, $params)->isNotEmpty();
    }

    private function matchingRules(string $event, array $params): Collection
    {
        $params['currentEventTypeName'] = strtolower($event);

        return $this->rulesFor(
            $event,
            $params
        )->filter(
            fn (Rule $rule) => $rule->matches($this->entity, $params)
        );
    }

    private function rulesFor(string $event, array $params): Collection
    {
        try {
            $ruleType = RuleType::getByName($event);
        } catch (ModelNotFoundException) {
            return new Collection();
        }

        $company = $params['company'] ?? null;
        if ($company && ! $company instanceof CompanyInterface) {
            $company = null;
        }

        return RuleRepository::getRulesByModelAndType(
            $this->app,
            $this->entity,
            $ruleType,
            $company
        );
    }
}
