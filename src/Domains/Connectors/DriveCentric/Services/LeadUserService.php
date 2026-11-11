<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;

class LeadUserService
{
    /**
     * Only a mapped owner becomes the salesperson. An unassigned lead (leads_owner_id = 0) returns
     * null on purpose: receiver-created leads carry the company's integration account as users_id,
     * and falling back to it would hand every inbound lead to that one DriveCentric user instead of
     * letting the dealership's own assignment rules run.
     */
    public static function resolve(Lead $lead): ?UserInterface
    {
        $owner = $lead->owner;

        if ($owner !== null && ! empty($owner->get(ConfigurationEnum::getUserKey($lead->company)))) {
            return $owner;
        }

        return null;
    }

    /**
     * DriveCentric names the assigned salesperson differently per endpoint (`salesperson1` on
     * byrange/upsert, `salesPerson` or `user` elsewhere), and some payloads carry a bare `userId`
     * instead of the identifiers array — so all four shapes have to be probed.
     */
    public static function extractSalespersonCrmId(array $deal): ?string
    {
        $salesperson = $deal['salesperson1'] ?? $deal['salesPerson'] ?? $deal['user'] ?? null;

        if (! is_array($salesperson)) {
            return null;
        }

        foreach ($salesperson['identifiers'] ?? [] as $identifier) {
            if (strtolower($identifier['type'] ?? '') === 'crmid' && ! empty($identifier['value'])) {
                return (string) $identifier['value'];
            }
        }

        return ! empty($salesperson['userId']) ? (string) $salesperson['userId'] : null;
    }

    /**
     * A deal's own identifier. Some payloads carry the CrmId as a bare `dealId` instead of an entry
     * in `identifiers`, so a scan of the array alone reports a deal as unidentifiable and its caller
     * skips it.
     */
    public static function extractDealIdentifier(array $deal, string $type = 'CrmId'): ?string
    {
        foreach ($deal['identifiers'] ?? [] as $identifier) {
            if (($identifier['type'] ?? '') === $type) {
                return $identifier['value'] ?? null;
            }
        }

        return $type === 'CrmId' && isset($deal['dealId']) ? (string) $deal['dealId'] : null;
    }
}
