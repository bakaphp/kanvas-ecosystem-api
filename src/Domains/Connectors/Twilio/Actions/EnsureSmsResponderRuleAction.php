<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Communication\Connections\Actions\EnsureAutoReplyRuleAction;
use Kanvas\Communication\Support\AutoReplyPolicy;
use Kanvas\Connectors\Twilio\Workflows\AgentChannelResponderActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Rules\DataTransferObject\RuleConditionData;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;
use Kanvas\Workflow\Rules\Models\Rule;

/**
 * The rule that makes an agent answer the company's inbound texts. The receiver and the rule are
 * per company, so one agent answers every SMS number the company has.
 */
class EnsureSmsResponderRuleAction
{
    public const string RULE_NAME = 'Twilio SMS auto-reply';

    public function __construct(private readonly Agent $agent)
    {
    }

    /**
     * @return Agent|null the agent answering the company's texts, which is another agent when one
     *                    with an SMS number already holds the rule; null when a rule a person set
     *                    up answers them
     */
    public function execute(): ?Agent
    {
        $holder = $this->currentHolder();
        if ($holder !== null) {
            return $holder;
        }

        $rule = new EnsureAutoReplyRuleAction(
            agent: $this->agent,
            // Not a channel column: the inbound job passes it when it fires the workflow.
            condition: new RuleConditionData('communication_channel', RuleConditionOperatorEnum::EQUAL, 'sms'),
            responder: AgentChannelResponderActivity::class,
            ruleName: self::RULE_NAME,
        )->execute();

        return $rule !== null ? $this->agent : null;
    }

    /**
     * An agent that gave its number up no longer holds the rule, so the next one takes it over.
     */
    private function currentHolder(): ?Agent
    {
        $rule = Rule::query()
            ->fromApp($this->agent->app)
            ->fromCompany($this->agent->company)
            ->where('name', self::RULE_NAME)
            ->notDeleted()
            ->first();

        $holderId = (int) ($rule?->params['agent_id'] ?? 0);
        if ($holderId === 0 || $holderId === $this->agent->getId()) {
            return null;
        }

        $holder = new AutoReplyPolicy()->agentWithId($this->agent->app, $this->agent->company, $holderId);

        return $holder?->smsPhoneNumber() !== null ? $holder : null;
    }
}
