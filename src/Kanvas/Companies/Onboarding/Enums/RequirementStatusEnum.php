<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Enums;

enum RequirementStatusEnum: string
{
    case MISSING = 'missing';
    case PENDING_CONNECT = 'pending_connect';
    case CONNECTED = 'connected';
    case SETUP_REQUESTED = 'setup_requested';
    case UPLOADED = 'uploaded';
    case NOT_USED = 'not_used';
    case NOT_LISTED = 'not_listed';

    public function satisfies(): bool
    {
        return $this !== self::MISSING;
    }
}
