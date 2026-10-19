<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Enums;

/**
 * Custom fields, on the company or else on the app, that switch parts of the CRM lead sync on.
 */
enum CrmLeadSyncSettingEnum: string
{
    /**
     * When true, 1 or 'true' the sync opens the sms and email channels of the lead's contacts itself,
     * as soon as everything is saved. It ignores guild_create_social_channels_on_contact_save.
     */
    case CREATE_SOCIAL_CHANNELS = 'crm_sync_create_social_channels';
}
