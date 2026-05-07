<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Enums;

enum ToolTypeEnum: string
{
    case SYSTEM = 'system';
    case CUSTOM = 'custom';
    case PLUGIN = 'plugin';
}
