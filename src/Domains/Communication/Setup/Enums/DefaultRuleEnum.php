<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Enums;

use Kanvas\Connectors\Google\Activities\CreateGoogleCalendarEventActivity;
use Kanvas\Connectors\SalesAssist\Activities\AIAssistChannelResponderActivity;
use Kanvas\Connectors\SalesAssist\Activities\SalesAssistAgentReachOutActivity;
use Kanvas\Connectors\Twilio\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Workflows\IndexKnowledgeDocumentActivity;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Workflows\TriggerIntelligenceActivity;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Workflows\Activities\PrivatizeConversationMessageActivity;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;

/**
 * The workflow rules every company runs its agents with, by the name the rule is saved under. Each
 * case knows the event and module it listens on, the activities it runs in order and the conditions
 * that keep it to its own traffic; the params that name company records are filled in by setup.
 */
enum DefaultRuleEnum: string
{
    case AGENT_REACH_OUT = 'AgentReachOut';
    case AI_ASSIST_RESPONDER = 'AI Assist Channel Responder';
    case PRIVATIZE_MESSAGES = 'Privatize Conversation Messages';
    case HUMAN_AGENT_RESPONSE = 'HumanAgentChannelResponseActivity';
    case TRIGGER_AI = 'Trigger ia';
    case INDEX_AGENT_KNOWLEDGE = 'Index agent knowledge documents';
    case GOOGLE_CALENDAR_EVENT = 'Create Google Calendar Event';

    public function event(): WorkflowEnum
    {
        return match ($this) {
            self::AGENT_REACH_OUT, self::PRIVATIZE_MESSAGES => WorkflowEnum::CREATED,
            // Channel::addMessage() fires it with the message, which is what these read.
            self::AI_ASSIST_RESPONDER, self::HUMAN_AGENT_RESPONSE => WorkflowEnum::UPDATED,
            self::TRIGGER_AI => WorkflowEnum::TRIGGER_AI,
            self::INDEX_AGENT_KNOWLEDGE => WorkflowEnum::ATTACH_FILE,
            self::GOOGLE_CALENDAR_EVENT => WorkflowEnum::EVENT_VERSIONS_WORKFLOW,
        };
    }

    /**
     * @return class-string
     */
    public function module(): string
    {
        return match ($this) {
            self::AGENT_REACH_OUT, self::TRIGGER_AI => Lead::class,
            self::AI_ASSIST_RESPONDER, self::HUMAN_AGENT_RESPONSE => Channel::class,
            self::PRIVATIZE_MESSAGES => Message::class,
            self::INDEX_AGENT_KNOWLEDGE => Agent::class,
            self::GOOGLE_CALENDAR_EVENT => EventVersion::class,
        };
    }

    /**
     * @return list<class-string> in the order they run
     */
    public function activities(): array
    {
        return match ($this) {
            // The new lead's AI mode first, so the reach-out reads the mode it was just given.
            self::AGENT_REACH_OUT => [TriggerIntelligenceActivity::class, SalesAssistAgentReachOutActivity::class],
            self::AI_ASSIST_RESPONDER => [AIAssistChannelResponderActivity::class],
            self::PRIVATIZE_MESSAGES => [PrivatizeConversationMessageActivity::class],
            self::HUMAN_AGENT_RESPONSE => [HumanAgentChannelResponseActivity::class],
            self::TRIGGER_AI => [TriggerIntelligenceActivity::class],
            self::INDEX_AGENT_KNOWLEDGE => [IndexKnowledgeDocumentActivity::class],
            self::GOOGLE_CALENDAR_EVENT => [CreateGoogleCalendarEventActivity::class],
        };
    }

    /**
     * @return list<array{0: string, 1: RuleConditionOperatorEnum, 2: string}> joined with AND
     */
    public function conditions(): array
    {
        return match ($this) {
            self::AGENT_REACH_OUT,
            self::PRIVATIZE_MESSAGES,
            self::TRIGGER_AI,
            self::GOOGLE_CALENDAR_EVENT => [['id', RuleConditionOperatorEnum::GREATER_THAN, '0']],
            self::AI_ASSIST_RESPONDER => [['name', RuleConditionOperatorEnum::EQUAL, 'AI Assist']],
            self::HUMAN_AGENT_RESPONSE => [
                ['message.message.from_human', RuleConditionOperatorEnum::EQUAL, 'true'],
                ['name', RuleConditionOperatorEnum::NOT_EQUAL, 'Default Channel'],
                ['name', RuleConditionOperatorEnum::NOT_EQUAL, 'Notes'],
                ['name', RuleConditionOperatorEnum::NOT_EQUAL, 'AI Assist'],
            ],
            self::INDEX_AGENT_KNOWLEDGE => [],
        };
    }

    /**
     * Params that do not depend on the company. The agent ids and the sender number are added by
     * setup from the company's own records.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return match ($this) {
            // Never an agent_id: the reach-out reads the company's agent_reach_out_default_agent_id.
            self::AGENT_REACH_OUT => ['trigger_type' => TriggersEnum::NEW_LEAD->value],
            default => [],
        };
    }
}
