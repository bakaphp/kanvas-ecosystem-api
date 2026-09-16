<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Enums;

enum DuplicateMatchEnum: string
{
    case SEMANTIC = 'semantic';
    case TITLE = 'title';
}
