<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesWorkflowCatalogForTool;
use Kanvas\Workflow\Rules\Actions\CreateRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Lets an admin build company automation by chat: "when a lead is created, notify the owner".
 *
 * Two hard limits, both structural rather than validated:
 *  - The rule's company is always the tool's own tenant — there is no company parameter, so a
 *    platform-wide rule (companies_id = 0, which every tenant on the app would then run) cannot be
 *    expressed. The tenant is re-checked anyway in case an agent is ever wired to the global company.
 *  - Authorization is on the HUMAN in the conversation, never the agent's own user — see
 *    requireRequestingAdminOrError().
 */
#[AgentTool(name: 'Create Company Workflow', category: 'workflow')]
class CreateCompanyWorkflowTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use ResolvesWorkflowCatalogForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_company_workflow',
            description: 'Creates an automation workflow for THIS company: when <trigger> happens on <entity>, '
                . 'run <actions> — optionally only when conditions match. Admin only: the person you are talking to '
                . 'must be a company administrator. The workflow always belongs to the current company; you cannot '
                . 'create a global/platform-wide workflow for other companies. Call list_workflow_options first to '
                . 'get the valid trigger, entity and action names — never invent them.',
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
                name: 'name',
                type: PropertyType::STRING,
                description: 'Short name for the workflow, e.g. "Notify owner on new lead".',
                required: true,
            ),
            new ToolProperty(
                name: 'entity',
                type: PropertyType::STRING,
                description: 'The record type the workflow watches, e.g. "Lead", "Order", "Message". Must be one of '
                    . 'the entities returned by list_workflow_options.',
                required: true,
            ),
            new ToolProperty(
                name: 'trigger',
                type: PropertyType::STRING,
                description: 'What makes the workflow run, e.g. "created", "updated", "after-adding-message-to-channel". '
                    . 'Must be one of the triggers returned by list_workflow_options.',
                required: true,
            ),
            new ToolProperty(
                name: 'actions',
                type: PropertyType::STRING,
                description: 'Comma-separated names of the activities to run, in order, e.g. '
                    . '"Send Lead Email, Push Lead To CRM". Must be names returned by list_workflow_options.',
                required: true,
            ),
            new ToolProperty(
                name: 'conditions',
                type: PropertyType::STRING,
                description: 'Optional filter — the workflow only runs when ALL of these match. One condition per '
                    . 'entry, separated by "|", written as "attribute operator value", e.g. '
                    . '"status == new | amount > 1000". Operators: ==, !=, >, >=, <, <=, in, not in, matches.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional description of what the workflow is for.',
                required: false,
            ),
            new ToolProperty(
                name: 'run_in_background',
                type: PropertyType::BOOLEAN,
                description: 'Optional, defaults to true. Runs the workflow on the queue. Pass false only when the '
                    . 'actions must finish before the triggering request returns.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        string $entity,
        string $trigger,
        string $actions,
        ?string $conditions = null,
        ?string $description = null,
        ?bool $run_in_background = null,
    ): array {
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $denied;
        }

        $name = trim($name);
        if ($name === '') {
            return $this->error('The workflow needs a name. Ask the admin what to call it.');
        }

        if (! isset($this->app) || ! isset($this->company) || ! isset($this->user)) {
            return $this->error('This agent has no company context, so it cannot create a workflow.');
        }

        if ($this->company->getId() === AppEnums::GLOBAL_COMPANY_ID->getValue()) {
            return $this->error(
                'This conversation is not scoped to a single company, and workflows can only be created for one '
                . 'company. Do not retry.'
            );
        }

        $ruleType = $this->resolveRuleType($trigger);
        if ($ruleType === null) {
            return $this->error(
                sprintf('"%s" is not a valid trigger. Pick one from available_triggers and retry.', trim($trigger)),
                ['available_triggers' => $this->availableTriggers()],
            );
        }

        $systemModule = $this->resolveSystemModule($entity);
        if ($systemModule === null) {
            return $this->error(
                sprintf('"%s" is not a record type this app automates. Pick one from available_entities and retry.', trim($entity)),
                ['available_entities' => $this->availableEntities()],
            );
        }

        $resolvedActions = [];
        foreach (array_filter(array_map('trim', explode(',', $actions)), fn (string $a): bool => $a !== '') as $actionName) {
            $action = $this->resolveAction($actionName);

            if ($action === null) {
                return $this->error(
                    sprintf('"%s" is not an available workflow activity. Pick one from suggested_actions and retry.', $actionName),
                    ['suggested_actions' => $this->searchActions($actionName) ?: $this->searchActions()],
                );
            }

            $resolvedActions[] = $action;
        }

        if ($resolvedActions === []) {
            return $this->error('A workflow needs at least one action to run. Call list_workflow_options to see them.');
        }

        $parsedConditions = $this->parseConditions($conditions);
        if (isset($parsedConditions['error'])) {
            return $this->error($parsedConditions['error']);
        }

        $existing = Rule::query()
            ->where('name', $name)
            ->where('rules_types_id', $ruleType->getId())
            ->where('systems_modules_id', $systemModule->getId())
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('is_deleted', 0)
            ->first();

        if ($existing !== null) {
            return [
                'created' => false,
                'already_exists' => true,
                'workflow_id' => $existing->getId(),
                'name' => $existing->name,
                'message' => 'A workflow with this name, trigger and entity already exists for this company. '
                    . 'Tell the admin instead of creating a duplicate.',
            ];
        }

        try {
            $rule = new CreateRuleAction(
                new RuleData(
                    app: $this->app,
                    company: $this->company,
                    user: $this->user,
                    ruleType: $ruleType,
                    systemModule: $systemModule,
                    name: $name,
                    description: $description !== null && trim($description) !== '' ? trim($description) : null,
                    pattern: $this->buildPattern(count($parsedConditions['conditions'])),
                    is_async: $run_in_background ?? true,
                    conditions: $parsedConditions['conditions'] === []
                        ? null
                        : RuleConditionData::collect($parsedConditions['conditions'], DataCollection::class),
                    actions: RuleActionData::collect(
                        array_map(
                            fn (Action $action, int $index): RuleActionData => new RuleActionData(
                                action: $action,
                                weight: (float) $index,
                            ),
                            $resolvedActions,
                            array_keys($resolvedActions)
                        ),
                        DataCollection::class
                    ),
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->error('The workflow could not be saved. Tell the admin it failed and do not retry.');
        }

        return [
            'created' => true,
            'workflow_id' => $rule->getId(),
            'name' => $rule->name,
            'entity' => $systemModule->name,
            'trigger' => $ruleType->name,
            'actions' => array_map(fn (Action $action): string => $action->name, $resolvedActions),
            'conditions' => array_map(
                fn (RuleConditionData $condition): string => sprintf(
                    '%s %s %s',
                    $condition->attribute_name,
                    $condition->operator->value,
                    (string) $condition->value
                ),
                $parsedConditions['conditions']
            ),
            'runs_in_background' => $rule->is_async,
            'scope' => 'company:' . $this->company->name,
            'message' => sprintf(
                'Workflow created for %s only. It runs on %s %s.',
                $this->company->name,
                $systemModule->name,
                $ruleType->name,
            ),
        ];
    }

    /**
     * @return array{conditions: list<RuleConditionData>, error?: string}
     */
    private function parseConditions(?string $conditions): array
    {
        $conditions = trim((string) $conditions);

        if ($conditions === '') {
            return ['conditions' => []];
        }

        $parsed = [];
        foreach (preg_split('/[|\n]/', $conditions) ?: [] as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            // Word operators are matched first and only when whitespace-delimited: an attribute such as
            // "min" contains "in", so a combined alternation would read "min > 3" as "m in > 3".
            // Symbolic operators are alternated longest-first so ">=" isn't cut down to ">".
            $wordOperators = '/^(?<attribute>.+?)\s+(?<operator>not in|in|matches)\s+(?<value>.+)$/i';
            $symbolOperators = '/^(?<attribute>.+?)\s*(?<operator>>=|<=|!=|==|=|>|<)\s*(?<value>.*)$/';

            if (! preg_match($wordOperators, $entry, $matches) && ! preg_match($symbolOperators, $entry, $matches)) {
                return [
                    'conditions' => [],
                    'error' => sprintf(
                        'Could not read the condition "%s". Write each one as "attribute operator value", e.g. '
                        . '"status == new", and separate them with "|".',
                        $entry
                    ),
                ];
            }

            $operator = mb_strtolower(trim($matches['operator']));
            $operator = $operator === '=' ? '==' : $operator;
            $value = trim(trim($matches['value']), '\'"');

            $parsed[] = new RuleConditionData(
                attribute_name: trim($matches['attribute']),
                operator: RuleConditionOperatorEnum::from($operator),
                value: $value === '' ? null : $value,
            );
        }

        return ['conditions' => $parsed];
    }

    private function buildPattern(int $conditionCount): string
    {
        return $conditionCount === 0 ? '1' : implode(' AND ', range(1, $conditionCount));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function error(string $message, array $extra = []): array
    {
        return array_merge(['created' => false, 'message' => $message], $extra);
    }
}
