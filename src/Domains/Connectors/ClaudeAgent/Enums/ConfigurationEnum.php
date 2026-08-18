<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Enums;

/**
 * App- and company-scoped settings (lowercase), as opposed to the agent-scoped UPPERCASE keys in
 * {@see CustomFieldEnum}. The API key is company-scoped so a tenant bringing its own gets its own
 * billing and rate limits, with an app-level fallback.
 */
enum ConfigurationEnum: string
{
    case NAME = 'ClaudeAgent';
    case API_KEY = 'claude_agents_api_key';
    case BASE_URL = 'claude_agents_base_url';
    case ENVIRONMENT_ID = 'claude_agents_environment_id';
}
