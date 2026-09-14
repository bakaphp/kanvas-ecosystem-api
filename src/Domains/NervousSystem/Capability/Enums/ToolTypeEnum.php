<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Enums;

enum ToolTypeEnum: string
{
    case SYSTEM = 'system';
    case CUSTOM = 'custom';
    case PLUGIN = 'plugin';
    case SUB_AGENT = 'sub_agent';

    /**
     * Backed by an `integrations` row instead of a PHP handler — the same shape SUB_AGENT uses with
     * `agents_id`. One row per MCP server; the methods it exposes are never catalog rows.
     */
    case MCP = 'mcp';
}
