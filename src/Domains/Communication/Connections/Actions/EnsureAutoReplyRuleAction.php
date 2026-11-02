<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Communication\Workflows\AgentChannelResponderActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Kanvas\Workflow\Services\WorkflowActionCatalog;

/**
 * One rule per tenant, on the conversation responder, with one condition per provider whose
 * conversations it answers. Not per connection: a rule's conditions are evaluated against the
 * channel, which carries no connection — the responder reads the message's connection and is what
 * declines to answer for one that is off.
 */
final class EnsureAutoReplyRuleAction
{
    public const string RULE_NAME = 'Communication auto-reply';

    /**
     * @param Agent $agent the company's answering agent; the rule is the agent's own tenant's
     * @param string $channelSlugPattern keeps the rule to this provider's conversations; without it
     *                                   the responder would be asked about every channel the tenant has
     * @param class-string|null $legacyResponder the activity this provider answered with before the
     *                                           conversation responder
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $channelSlugPattern,
        private readonly ?string $legacyResponder = null
    ) {
    }

    public function execute(): ?Rule
    {
        $app = $this->agent->app;
        $company = $this->agent->company;
        $catalog = new WorkflowActionCatalog();
        $action = $catalog->register(AgentChannelResponderActivity::class);
        $ruleType = RuleType::getByName(WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value);
        $systemModule = SystemModulesRepository::getByModelName(Channel::class, $app);

        // A rule a person already set up is how this tenant answers today. Ours as well would answer
        // every message twice.
        $answeringActions = array_filter([$action, $this->legacyResponder !== null ? $catalog->register($this->legacyResponder) : null]);

        if ($this->tenantAlreadyAnswersWith($answeringActions, $ruleType->getId())) {
            return null;
        }

        $rule = Rule::query()->firstOrCreate(
            [
                'name' => self::RULE_NAME,
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModule->getId(),
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
            ],
            [
                'params' => ['agent_id' => $this->agent->getId()],
                'pattern' => '1',
                'is_async' => 1,
                'is_deleted' => 0,
            ]
        );

        if (($rule->params['agent_id'] ?? null) !== $this->agent->getId()) {
            $rule->params = array_merge($rule->params ?? [], ['agent_id' => $this->agent->getId()]);
        }

        $rule->getRulesConditions()->firstOrCreate(
            [
                'attribute_name' => 'slug',
                'operator' => RuleConditionOperatorEnum::MATCHES->value,
                'value' => $this->channelSlugPattern,
            ],
            ['is_deleted' => 0],
        );

        // Any provider's conversation is enough: the placeholders number the conditions in the
        // order the rule reads them.
        $rule->pattern = implode(' or ', range(1, $rule->getRulesConditions()->count()));
        $rule->saveOrFail();

        $ruleWorkflowAction = RuleWorkflowAction::query()->firstOrCreate(
            ['system_modules_id' => $systemModule->getId(), 'actions_id' => $action->getId()],
            ['is_deleted' => 0],
        );

        RuleAction::query()->firstOrCreate(
            ['rules_id' => $rule->getId(), 'rules_workflow_actions_id' => $ruleWorkflowAction->getId()],
            ['weight' => 0, 'is_deleted' => 0],
        );

        return $rule;
    }

    /**
     * Matched by the action's name as well as its row: until the repoint migration has run, a rule
     * made before the connector moved points at the row that still carries the old class name.
     *
     * @param list<Action> $actions
     */
    private function tenantAlreadyAnswersWith(array $actions, int $ruleTypeId): bool
    {
        $actionIds = Action::query()
            ->whereIn('name', array_map(fn (Action $action): string => $action->name, $actions))
            ->pluck('id')
            ->merge(array_map(fn (Action $action): int => $action->getId(), $actions))
            ->unique()
            ->all();

        return Rule::query()
            ->fromApp($this->agent->app)
            ->fromCompany($this->agent->company)
            ->where('rules_types_id', $ruleTypeId)
            ->where('name', '!=', self::RULE_NAME)
            ->notDeleted()
            ->whereHas('workflowActivities.activity', fn (Builder $query) => $query->whereIn('actions_id', $actionIds))
            ->exists();
    }
}
