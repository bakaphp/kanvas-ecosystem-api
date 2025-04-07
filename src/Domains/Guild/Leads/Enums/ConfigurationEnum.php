<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum ConfigurationEnum: string
{
    case SEND_NEW_LEAD_NOTIFICATION = 'guild_send_new_lead_notification';
    case SEND_NEW_LEAD_TO_OWNER_NOTIFICATION = 'guild_send_new_lead_to_owner_notification';
}
