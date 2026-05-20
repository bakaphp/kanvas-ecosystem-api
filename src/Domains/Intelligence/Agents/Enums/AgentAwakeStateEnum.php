<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AgentAwakeStateEnum: string
{
    case AWAKE = 'awake';
    case SLEEPING = 'sleeping';
    case OFFLINE = 'offline';
}
