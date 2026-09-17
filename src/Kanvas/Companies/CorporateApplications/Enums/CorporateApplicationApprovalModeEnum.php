<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Baka\Contracts\AppInterface;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Whether an application coming through a receiver is approved on the spot or queued for a
 * reviewer. Lives on the receiver (custom field `approval_mode`) so one app can run several
 * application receivers with different policies — the corporate one manual because self-reported
 * RNCs proved unreliable, a parking one flipped to auto per environment without a deploy.
 */
enum CorporateApplicationApprovalModeEnum: string
{
    case MANUAL = 'manual';
    case AUTO = 'auto';

    public const string RECEIVER_KEY = 'approval_mode';

    /**
     * The receiver's own setting wins. The app-level pair (`movipass_corporate_receiver_id` +
     * `movipass_corporate_auto_approve`) stays as the fallback so receivers configured before the
     * key existed keep working. Null means the receiver takes no applications at all.
     */
    public static function resolveFor(Lead $lead, AppInterface $app): ?self
    {
        $receiverMode = $lead->receiver?->get(self::RECEIVER_KEY);

        if (is_string($receiverMode) && $receiverMode !== '') {
            return self::from($receiverMode);
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
