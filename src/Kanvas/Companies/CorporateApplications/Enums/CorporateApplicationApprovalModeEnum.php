<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Baka\Contracts\AppInterface;
use Kanvas\Guild\Leads\Models\Lead;

enum CorporateApplicationApprovalModeEnum: string
{
    case MANUAL = 'manual';
    case AUTO = 'auto';

    public const string RECEIVER_KEY = 'approval_mode';

    public static function resolveFor(Lead $lead, AppInterface $app): ?self
    {
        $receiverMode = $lead->receiver?->get(self::RECEIVER_KEY);

        $receiverOverride = is_string($receiverMode) ? self::tryFrom($receiverMode) : null;

        if ($receiverOverride !== null) {
            return $receiverOverride;
        }

        $corporateReceiverId = (int) (CorporateApplicationSettingEnum::RECEIVER_ID->readFrom($app) ?? 0);

        if ($corporateReceiverId === 0 || (int) $lead->leads_receivers_id !== $corporateReceiverId) {
            return null;
        }

        return (bool) (CorporateApplicationSettingEnum::AUTO_APPROVE->readFrom($app) ?? false)
            ? self::AUTO
            : self::MANUAL;
    }
}
