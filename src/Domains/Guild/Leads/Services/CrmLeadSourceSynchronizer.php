<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketCustomFieldEnum;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums as DriveCentricCustomFieldEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EleadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Actions\PullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as VinSolutionCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadClosingStatusAction;

/**
 * Asks the CRM that owns a lead for its current state, then re-applies the closing-status trigger
 * so a CRM close flips the `ai_mode` / `ai_follow_up` flags the follow-up gates read. Reynolds is
 * webhook-only and has nothing to pull.
 */
class CrmLeadSourceSynchronizer
{
    /**
     * Without the external id the pull degrades into a people search by phone, which can create or
     * close leads as a side effect — not something an hourly cron should trigger.
     */
    public function canSync(Lead $lead): bool
    {
        $externalIdKey = match (new PullLeadAction($lead)->detectConnector()) {
            'elead' => EleadCustomFieldEnum::LEAD_ID->value,
            'vinsolution' => VinSolutionCustomFieldEnum::LEADS->value,
            'dealersocket' => DealerSocketCustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value,
            'drivecentric' => DriveCentricCustomFieldEnum::DRIVE_CENTRIC_DEAL_ID->value,
            default => null,
        };

        return $externalIdKey !== null && filled($lead->get($externalIdKey));
    }

    public function sync(Lead $lead): void
    {
        new PullLeadAction($lead)->execute();
        new ApplyLeadClosingStatusAction($lead)->execute();
    }
}
