<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Enums;

enum ConfigurationEnum: string
{
    case AGENT_ID = 'agent_id';
    case SIGNING_SECRET = 'signing_secret';
    case BOT_USER_ID = 'bot_user_id';
    case TEAM_ID = 'team_id';
    case TEAM_NAME = 'team_name';

    // Workspace listener only. The agent app keeps its bot token on the agent (the container
    // runtimes read the same key); the listener has no agent, so its token lives beside the
    // signing secret it was pasted with.
    case BOT_TOKEN = 'bot_token';
    case CHANNEL_DENY_LIST = 'channel_deny_list';
    case INGEST_FILES = 'ingest_files';
    case CHANNELS_JOINED = 'channels_joined';

    // Agent app only: rooms whose history has been pulled once the bot could read them, and how far
    // back that pull reaches (days). Both live on the receiver because the agent's own custom fields
    // are shared with the container runtimes.
    case CHANNELS_BACKFILLED = 'channels_backfilled';
    case BACKFILL_DAYS = 'backfill_days';
}
