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
    case LISTEN_ALL_CHANNELS = 'listen_all_channels';
}
