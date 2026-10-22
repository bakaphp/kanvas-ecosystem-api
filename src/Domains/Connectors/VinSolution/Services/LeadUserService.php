<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

class LeadUserService
{
    /**
     * Only a mapped owner is the salesperson. An unassigned lead (leads_owner_id = 0) returns null
     * on purpose: receiver-created leads carry the company's integration account as users_id, and
     * falling back to it made that account the sales rep of every inbound lead in VinSolutions
     * instead of letting the dealership's own assignment rules run.
     */
    public static function resolve(Lead $lead): ?UserInterface
    {
        $owner = $lead->owner;

        if (self::isMapped($owner, $lead->company)) {
            return $owner;
        }

        return null;
    }

    /**
     * VinSolutions needs a userId on every call even when nobody is assigned, so calls on an
     * unassigned lead run as the company's operating account rather than the lead's creator.
     */
    public static function actingUser(Lead $lead): ?UserInterface
    {
        return self::resolve($lead) ?? self::operatingUser($lead->company);
    }

    /**
     * The company-level VinSolutions account: the opted-in DOWNLOAD_ALL_LEADS_USER when present,
     * otherwise the company owner.
     */
    public static function operatingUser(Companies $company): ?Users
    {
        $downloadUserId = $company->get(ConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value);

        if (! empty($downloadUserId)) {
            return Users::getById((int) $downloadUserId);
        }

        return $company->user;
    }

    public static function mappedOperatingUserOrFail(Companies $company): Users
    {
        $user = self::operatingUser($company);

        if (! self::isMapped($user, $company)) {
            throw new VinSolutionException(
                'No VinSolution operating user for company ' . $company->getId()
                . ': map the company owner or set ' . ConfigurationEnum::DOWNLOAD_ALL_LEADS_USER->value
            );
        }

        return $user;
    }

    public static function isMapped(?UserInterface $user, Companies $company): bool
    {
        return $user !== null && ! empty($user->get(ConfigurationEnum::getUserKey($company, $user)));
    }
}
