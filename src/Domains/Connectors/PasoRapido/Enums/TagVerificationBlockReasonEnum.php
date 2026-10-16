<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Enums;

enum TagVerificationBlockReasonEnum: string
{
    case IP_AUTO_BLOCKED = 'ip_auto_blocked';
    case UNVERIFIED_ACCOUNT = 'unverified_account';
    case COMPANY_BLOCKED = 'company_blocked';
    case TAG_NOT_OWNED = 'tag_not_owned';
    case IP_MAX_USERS = 'ip_max_users';
    case IP_MAX_DAILY = 'ip_max_daily';
    case USER_MAX_DAILY = 'user_max_daily';
    case SEQUENTIAL_SCAN = 'sequential_scan';
    case USER_MAX_ATTEMPTS = 'user_max_attempts';
}
