<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Enums;

/**
 * The sales agent setup gives a company: the name it is created under, and the company setting its
 * id is recorded in. Who answers a conversation is the connection's owner, never this setting.
 */
enum ConversationAgentEnum: string
{
    case SETTING = 'sales_agent_id';

    case DEFAULT_NAME = 'Sally';
}
