<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\AssemblesWorkflowRuleForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesWorkflowCatalogForTool;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Change automation that already exists, so "add one more condition" stops meaning "build a second
 * workflow and remember to delete the first".
 *
 * What it deliberately cannot change is the **entity and trigger**: those are what the rule is matched
 * on, so altering them turns the rule into a different rule while keeping its history and its id. A
 * rule watching the wrong record type has to be replaced, and saying so is more honest than silently
 * rewriting it.
 *
 * Every field is replace-not-merge. A partial update of conditions is ambiguous — "add is_publish"
 * and "now the only condition is is_publish" would look identical — so the caller states the full set
 * it wants to end up with.
 */
#[AgentTool(name: 'Update Company Workflow', category: 'workflow')]
class UpdateCompanyWorkflowTool extends Tool implements HasRunKey
{
    use AssemblesWorkflowRuleForTool;
    use GuardsAdminForTool;
    use ResolvesWorkflowCatalogForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'update_company_workflow',
            description: 'Change an existing workflow for THIS company — its conditions, the settings it '
                . 'passes, which activities it runs, its name, or whether it is active. Admin only. Call '
                . 'list_company_workflows first to get the workflow_id and see what it looks like now. Every '
                . 'field REPLACES what is there: pass the complete set you want to end up with, not just the '
                . 'part you are adding. You cannot change what record type or event it watches — that is a '
                . 'different workflow, so create a new one and deactivate this.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'workflow_id',
                type: PropertyType::INTEGER,
                description: 'The workflow to change, from list_company_workflows.',
                required: true,
            ),
            new ToolProperty(
                name: 'conditions',
                type: PropertyType::STRING,
                description: 'The COMPLETE set of conditions, replacing the current ones. One per entry, '
                    . 'separated by "|", written as "attribute operator value" — e.g. '
                    . '"message_type_id == 2782 | is_publish == 1". Operators: ==, !=, >, >=, <, <=, in, '
                    . 'not in, matches. Pass "none" to remove all conditions so it runs on every record.',
                required: false,
            ),
            new ToolProperty(
                name: 'params',
                type: PropertyType::STRING,
                description: 'The COMPLETE settings object, replacing the current one, e.g. '
                    . '{"message_type_id": 2782, "status": "pending"}. Read the action\'s params in '
                    . 'list_workflow_options first — dropping a required one here breaks the workflow.',
                required: false,
            ),
            new ToolProperty(
                name: 'actions',
                type: PropertyType::STRING,
                description: 'The COMPLETE comma-separated list of activities to run, in order, replacing '
                    . 'the current ones. Use names from list_workflow_options verbatim.',
                required: false,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Optional new name for the workflow.',
                required: false,
            ),
            new ToolProperty(
                name: 'is_active',
                type: PropertyType::BOOLEAN,
                description: 'Pass false to deactivate the workflow so it stops running. It is kept, not '
                    . 'destroyed, and can be reactivated with true.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $workflow_id,
        ?string $conditions = null,
        ?string $params = null,
        ?string $actions = null,
        ?string $name = null,
        ?bool $is_active = null,
    ): array {
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $denied;
        }

        if (! $this->hasTenantContext()) {
            return $this->error('This agent has no company context, so it cannot change a workflow.');
        }

        // Scoped to the caller's own company, so an id from another tenant simply is not found.
        $rule = Rule::query()
            ->where('id', $workflow_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->first();

        if ($rule === null) {
            return $this->error(
                'Workflow ' . $workflow_id . ' does not belong to this company. Use list_company_workflows '
                . 'to get the right id.'
            );
        }

        if ($conditions === null && $params === null && $actions === null && $name === null && $is_active === null) {
            return $this->error('Nothing to change — pass at least one of conditions, params, actions, name '
                . 'or is_active.');
        }

        $parsedConditions = null;

        if ($conditions !== null) {
            // "none" is the only way to say "no conditions at all"; an empty string is indistinguishable
            // from the caller simply not touching conditions.
            $wantsNoConditions = in_array(mb_strtolower(trim($conditions)), ['none', 'no conditions', '-'], true);
            $parsed = $wantsNoConditions ? ['conditions' => []] : $this->parseConditions($conditions);

            if (isset($parsed['error'])) {
                return $this->error($parsed['error']);
            }

            // Even "clear them all" keeps one: a rule states what it matches, and "everything" is a
            // statement too.
            $parsedConditions = $this->withDefaultCondition($parsed['conditions']);
        }

        $parsedParams = null;

        if ($params !== null) {
            $parsed = $this->parseParams($params);

            if (isset($parsed['error'])) {
                return $this->error($parsed['error']);
            }

            $parsedParams = $parsed['params'];
        }

        $resolvedActions = null;

        if ($actions !== null) {
            $actionList = $this->resolveActionList(
                $actions,
                'A workflow needs at least one action. Leave actions out to keep the current ones.'
            );

            if (isset($actionList['error'])) {
                return $actionList['error'];
            }

            $resolvedActions = $actionList['actions'];
        }

        if ($parsedParams !== null && $resolvedActions === null) {
            // Params are checked against whatever the workflow actually runs, not against what this call
            // happens to mention — otherwise dropping a required param would pass unnoticed.
            $current = $this->actionsOfRule($rule);

            if ($current !== [] && ($denied = $this->refuseBadParams($parsedParams, $current)) !== null) {
                return $denied;
            }
        }

        if ($parsedParams !== null && $resolvedActions !== null
            && ($denied = $this->refuseBadParams($parsedParams, $resolvedActions)) !== null) {
            return $denied;
        }

        try {
            DB::connection($rule->getConnectionName())->transaction(function () use (
                $rule,
                $parsedConditions,
                $parsedParams,
                $resolvedActions,
                $name,
                $is_active
            ): void {
                $attributes = [];

                if ($name !== null && trim($name) !== '') {
                    $attributes['name'] = trim($name);
                }

                if ($parsedParams !== null) {
                    $attributes['params'] = $parsedParams;
                }

                if ($is_active !== null) {
                    $attributes['is_deleted'] = $is_active ? 0 : 1;
                }

                if ($parsedConditions !== null) {
                    $attributes['pattern'] = $this->buildPattern(count($parsedConditions));
                }

                if ($attributes !== []) {
                    $rule->update($attributes);
                }

                if ($parsedConditions !== null) {
                    $this->replaceConditions($rule, $parsedConditions);
                }

                if ($resolvedActions !== null) {
                    $this->replaceActions($rule, $resolvedActions);
                }
            });
        } catch (Throwable $e) {
            report($e);

            return $this->error('The workflow could not be updated. Tell the admin it failed and do not retry.');
        }

        $rule->refresh();

        return [
            'updated' => true,
            'workflow_id' => $rule->getId(),
            'name' => $rule->name,
            'is_active' => ! $rule->is_deleted,
            'conditions' => array_map(
                fn (RuleConditionData $condition): string => trim(sprintf(
                    '%s %s %s',
                    $condition->attribute_name,
                    $condition->operator->value,
                    (string) $condition->value
                )),
                $parsedConditions ?? []
            ),
            'params' => is_array($rule->params) ? $rule->params : [],
            'message' => 'Updated. It applies to records created from now on; anything already processed is '
                . 'unaffected.',
            ...$this->conditionWarningsFor($rule, $parsedConditions),
        ];
    }

    /**
     * @param list<RuleConditionData> $conditions
     */
    private function replaceConditions(Rule $rule, array $conditions): void
    {
        $rule->getRulesConditions()->delete();

        foreach ($conditions as $condition) {
            $rule->getRulesConditions()->create([
                'attribute_name' => $condition->attribute_name,
                'operator' => $condition->operator->value,
                'value' => $condition->value,
                'is_deleted' => 0,
            ]);
        }
    }

    /**
     * @param list<Action> $actions
     */
    private function replaceActions(Rule $rule, array $actions): void
    {
        RuleAction::query()->where('rules_id', $rule->getId())->delete();

        $weight = 0;

        foreach ($actions as $action) {
            $activity = RuleWorkflowAction::firstOrCreate(
                [
                    'system_modules_id' => $rule->systems_modules_id,
                    'actions_id' => $action->getId(),
                ],
                ['is_deleted' => 0],
            );

            RuleAction::create([
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => $activity->getId(),
                'weight' => $weight++,
                'is_deleted' => 0,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    /**
     * @param list<RuleConditionData>|null $conditions
     * @return array<string, mixed>
     */
    private function conditionWarningsFor(Rule $rule, ?array $conditions): array
    {
        $module = $rule->systemModule;

        if ($conditions === null || $module === null) {
            return [];
        }

        return $this->conditionWarnings($module, $conditions, is_array($rule->params) ? $rule->params : []);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<Action> $actions
     * @return array<string, mixed>|null
     */
    private function refuseBadParams(array $params, array $actions): ?array
    {
        ['known' => $known, 'unknown' => $unknown, 'missing' => $missing] = $this->auditParams($params, $actions);

        if ($unknown !== []) {
            return $this->error(
                sprintf('"%s" is not read by this workflow\'s actions.', implode('", "', $unknown)),
                ['accepted_params' => $known],
            );
        }

        if ($missing !== []) {
            return $this->error(
                sprintf(
                    'params REPLACES the current settings, and these required ones are missing: %s. Include '
                    . 'them or the workflow will misbehave.',
                    implode(', ', array_keys($missing))
                ),
                ['accepted_params' => $known],
            );
        }

        return null;
    }

    protected function outcomeKey(): string
    {
        return 'updated';
    }

    protected function requiredAbilities(): array
    {
        return [AgentAbilityEnum::MANAGE_COMPANY_WORKFLOWS->value];
    }
}
