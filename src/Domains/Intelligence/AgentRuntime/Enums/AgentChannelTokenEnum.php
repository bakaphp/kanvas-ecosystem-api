<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

// Shared keys for agent channel credentials. A Slack token's value doesn't change based on
// which runtime reads it, so we store it once here and let each runtime's DockerComposeBuilder
// inject the canonical container env var. Legacy `OPENCLAW_*`/`HERMES_*` rows were backfilled
// into these keys by `2026_05_14_000000_unify_agent_channel_token_keys`.
enum AgentChannelTokenEnum: string
{
    case SLACK_BOT_TOKEN = 'AGENT_SLACK_BOT_TOKEN';
    case SLACK_APP_TOKEN = 'AGENT_SLACK_APP_TOKEN';
    case TELEGRAM_BOT_TOKEN = 'AGENT_TELEGRAM_BOT_TOKEN';
}
