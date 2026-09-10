<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Enums;

enum McpAuthEnum: string
{
    case NONE = 'none';
    case BEARER = 'bearer';
    case HEADER = 'header';
    case OAUTH = 'oauth';

    /**
     * OAuth tokens expire, so they are resolved through the refresh path rather than read straight
     * out of the company setting.
     */
    public function needsRefresh(): bool
    {
        return $this === self::OAUTH;
    }
}
