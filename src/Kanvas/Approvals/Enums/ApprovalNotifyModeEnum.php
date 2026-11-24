<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

enum ApprovalNotifyModeEnum: string
{
    case ALL = 'all';
    case FIRST = 'first';
    case NONE = 'none';

    /**
     * One email per approver for everything opened within SendApprovalDigestJob::WINDOW_MINUTES, so a
     * 15-row import is one email rather than fifteen.
     */
    case DIGEST = 'digest';

    public static function fromSetting(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::ALL;
    }
}
