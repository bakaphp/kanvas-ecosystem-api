<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ghost\Enums;

enum CustomFieldEnum: string
{
    case GHOST_MEMBER_ID = 'ghost_member_id';
    case GHOST_MEMBER_UUID = 'ghost_member_uuid';
    case GHOST_UNLOCK_CUSTOM_FIELD = 'unlocked_reports';
    case WEBHOOK_IS_REPORT_EVENT = 'is_report_event';
}
