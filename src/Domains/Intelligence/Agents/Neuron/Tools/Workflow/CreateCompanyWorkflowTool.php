<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\AssemblesWorkflowRuleForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesWorkflowCatalogForTool;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Rules\Actions\CreateRuleAction;
use Kanvas\Workflow\Rules\DataTransferObject\Rule as RuleData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleActionData;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleType;
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
    use AssemblesWorkflowRuleForTool;
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
                name: 'params',
                type: PropertyType::STRING,
                description: 'Settings for the actions, as a JSON object, e.g. '
                    . '{"message_type_id": 42, "status": "pending", "categories": ["News"]}. Call '
                    . 'list_workflow_options first and read each action\'s "params" — an action listing '
                    . '"required_params" will not be accepted without them. The params are shared by every '
                    . 'action in the workflow, so put actions that need conflicting settings in separate '
                    . 'workflows.',
                required: false,
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
        ?string $params = null,
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

        if (! $this->hasTenantContext() || ! isset($this->user)) {
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
            // Suggestions are scoped to what the caller asked for. Dumping the first N alphabetically
            // reads as the complete set and hid the real Message entity, which sorts past the cap.
            $suggestions = $this->suggestEntities($entity);

            return $this->error(
                sprintf(
                    '"%s" is not a record type this app automates. Pick one from available_entities and retry.',
                    trim($entity)
                ),
                [
                    'available_entities' => $suggestions,
                    'note' => 'These are matches for your term, not the whole catalog. Search a different '
                        . 'word if none fit — do not conclude the entity does not exist.',
                ],
            );
        }

        $fit = $this->checkTriggerEntityFit($ruleType, $systemModule);

        if ($fit !== null && $fit['refuse']) {
            return $this->error($fit['message'] . ' Create it on that entity instead.');
        }

        $actionList = $this->resolveActionList(
            $actions,
            'A workflow needs at least one action to run. Call list_workflow_options to see them.'
        );

        if (isset($actionList['error'])) {
            return $actionList['error'];
        }

        $resolvedActions = $actionList['actions'];

        $parsedParams = $this->parseParams($params);
        if (isset($parsedParams['error'])) {
            return $this->error($parsedParams['error']);
        }

        $paramCheck = $this->refuseBadParams($parsedParams['params'], $resolvedActions);
        if ($paramCheck !== null) {
            return $paramCheck;
        }

        $parsedConditions = $this->parseConditions($conditions);
        if (isset($parsedConditions['error'])) {
            return $this->error($parsedConditions['error']);
        }

        $parsedConditions['conditions'] = $this->withDefaultCondition($parsedConditions['conditions']);

        if (($duplicate = $this->refuseDuplicate($name, $ruleType, $systemModule)) !== null) {
            return $duplicate;
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
                    params: $parsedParams['params'],
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

        return $this->describeCreated(
            $rule,
            $ruleType,
            $systemModule,
            $resolvedActions,
            $parsedConditions['conditions'],
            $parsedParams['params'],
            $fit,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function refuseDuplicate(string $name, RuleType $ruleType, SystemModules $systemModule): ?array
    {
        $existing = Rule::query()
            ->where('name', $name)
            ->where('rules_types_id', $ruleType->getId())
            ->where('systems_modules_id', $systemModule->getId())
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('is_deleted', 0)
            ->first();

        if ($existing === null) {
            return null;
        }

        return [
            'created' => false,
            'already_exists' => true,
            'workflow_id' => $existing->getId(),
            'name' => $existing->name,
            'message' => 'A workflow with this name, trigger and entity already exists for this company. '
                . 'Tell the admin instead of creating a duplicate.',
        ];
    }

    /**
     * @param list<Action> $resolvedActions
     * @param list<RuleConditionData> $conditions
     * @param array<string, mixed> $params
     * @param array{message: string, refuse: bool}|null $fit
     * @return array<string, mixed>
     */
    private function describeCreated(
        Rule $rule,
        RuleType $ruleType,
        SystemModules $systemModule,
        array $resolvedActions,
        array $conditions,
        array $params,
        ?array $fit,
    ): array {
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
                $conditions
            ),
            'params' => $params,
            'runs_in_background' => $rule->is_async,
            ...$this->conditionWarnings($systemModule, $conditions, $params),
            ...($fit !== null ? ['entity_warning' => $fit['message']] : []),
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
     * @return list<string>
     */
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
                sprintf(
                    '%s not read by any of these actions. Use list_workflow_options to see what they take.',
                    count($unknown) === 1
                        ? sprintf('"%s" is', reset($unknown))
                        : sprintf('"%s" are', implode('", "', $unknown))
                ),
                ['accepted_params' => $known],
            );
        }

        if ($missing !== []) {
            return $this->error(
                sprintf(
                    'These params are required and were not given: %s. Ask the admin for the values — do not '
                    . 'guess, and do not create the workflow without them.',
                    implode(', ', array_map(
                        fn (string $name, string $actionName): string => sprintf('%s (%s)', $name, $actionName),
                        array_keys($missing),
                        $missing
                    ))
                ),
                ['accepted_params' => $known],
            );
        }

        return null;
    }

    protected function requiredAbilities(): array
    {
        return [AgentAbilityEnum::MANAGE_COMPANY_WORKFLOWS->value];
    }
}
