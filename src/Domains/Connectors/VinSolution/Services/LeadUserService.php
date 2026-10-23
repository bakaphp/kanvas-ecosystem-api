<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;

class LeadUserService
{
    /**
     * VinSolutions has to see the assigned salesperson, so the owner wins over the lead's creator.
     * Both sides are nullable — an unassigned lead carries leads_owner_id = 0 and stale imports
     * carry users_id = 0, so callers get null instead of a fatal on a missing relation.
     */
    public static function resolve(Lead $lead): ?UserInterface
    {
        $owner = $lead->owner;

        if ($owner !== null && ! empty($owner->get(ConfigurationEnum::getUserKey($lead->company, $owner)))) {
            return $owner;
        }

        return $lead->user;
    }
}
