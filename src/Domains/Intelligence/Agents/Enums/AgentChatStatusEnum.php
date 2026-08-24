<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AgentChatStatusEnum: string
{
    case COMPLETED = 'completed';
    case PENDING = 'pending';
}
