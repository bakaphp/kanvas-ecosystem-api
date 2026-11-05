<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Enums;

/**
 * Which agent answers a company's conversations: the one the company setting names, else the
 * company's Sally.
 */
enum ConversationAgentEnum: string
{
    case SETTING = 'sales_agent_id';

    case DEFAULT_NAME = 'Sally';
}
