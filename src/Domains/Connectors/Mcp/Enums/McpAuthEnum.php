<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Enums;

enum McpAuthEnum: string
{
    case NONE = 'none';
    case BEARER = 'bearer';
    case HEADER = 'header';
    case OAUTH = 'oauth';
}
