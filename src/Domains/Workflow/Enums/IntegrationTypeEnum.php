<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

/**
 * How an integration is connected, so each UI can list only the rows it knows how to set up — the
 * generic config form handles KEY; MCP servers are connected per agent and must never reach that form.
 */
enum IntegrationTypeEnum: string
{
    case KEY = 'key';
    case OAUTH = 'oauth';
    case MCP = 'mcp';
}
