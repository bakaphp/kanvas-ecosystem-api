<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

class LeadUserService
{
    /**
     * eLead has to see the assigned salesperson, so the owner wins over the lead's creator.
     */
    public static function resolve(Lead $lead, bool $requireJobPosition = false): ?UserInterface
    {
        foreach ([$lead->owner, $lead->user] as $user) {
            if ($user === null || empty($user->get(CustomFieldEnum::getUserKey($lead->company)))) {
                continue;
            }

            if ($requireJobPosition && empty($user->get(CustomFieldEnum::getUserJobPositionKey($lead->company)))) {
                continue;
            }

            return $user;
        }

        return null;
    }
}
