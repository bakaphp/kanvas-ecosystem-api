<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Services;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\UserConfig;
use Kanvas\Users\Models\Users;

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

    public static function resolveByEleadUserId(Companies $company, ?string $eleadUserId): ?Users
    {
        if (Str::trimToNull($eleadUserId) === null) {
            return null;
        }

        $config = UserConfig::where([
            'name' => CustomFieldEnum::getUserKey($company),
            'value' => $eleadUserId,
        ])->first();

        return $config?->user;
    }
}
