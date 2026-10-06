<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * Social message-type verbs the agent runtime writes for itself. `agent_summary` is deliberately not
 * LeadMessageTypeEnum::CONVERSATION_SUMMARY (`summary`): that one is the lead conversation summary a
 * human reads on the lead, this one is what the agent compacted its own working history into.
 */
enum AgentMessageTypeEnum: string
{
    case AGENT_SUMMARY = 'agent_summary';
}
