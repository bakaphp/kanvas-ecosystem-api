<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Support;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

/**
 * The `agent-read-access-companies` grant is an upper bound; membership of the requesting human is
 * the truth, re-read every turn.
 */
final class AgentReadAccessCompanies
{
    /**
     * @return list<int>
     */
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    public static function grantedCompanyIds(Agent $agent): array
    {
        return self::normalize($agent->get(ConfigurationEnum::AGENT_READ_ACCESS_COMPANIES->value));
    }

    /**
     * Without the membership check an admin of one company could grant their agent another by id.
     *
     * @return list<int> the normalized ids to store
     */
    public static function assertGrantable(Agent $agent, Users $actor, mixed $value): array
    {
        $ids = self::normalize($value);

        if ($ids === []) {
            return [];
        }

        $app = $agent->app;
        $inApp = UserCompanyApps::query()
            ->where('apps_id', $app->getId())
            ->whereIn('companies_id', $ids)
            ->pluck('companies_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $outsideApp = array_diff($ids, $inApp);

        if ($outsideApp !== []) {
            throw new ValidationException(
                'Companies ' . implode(', ', $outsideApp) . ' do not belong to this app.'
            );
        }

        if (! $actor->isOwnerOf($app)) {
            $notMember = array_diff($ids, self::memberCompanyIds($actor, $app));

            if ($notMember !== []) {
                throw new ValidationException(
                    'You are not a member of companies ' . implode(', ', $notMember)
                    . ', so you cannot grant an agent read access to them.'
                );
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    public static function memberCompanyIds(Users $user, Apps $app): array
    {
        return UsersAssociatedApps::query()
            ->where('users_id', $user->getId())
            ->where('apps_id', $app->getId())
            ->notDeleted()
            ->pluck('companies_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Own company only on a customer surface (a prospect must never see another company) or with no
     * human at the keyboard (channel turns run as the shared AI user, which may belong to several
     * companies).
     */
    public static function scopeFor(Agent $agent, Companies $ownCompany, Users $requestingUser): CompanyScope
    {
        $requested = self::grantedCompanyIds($agent);
        $agentId = (int) $agent->getId();

        $app = $agent->app;

        $ownCompanyOnly = $requested === []
            || $agent->conversesWithCustomer()
            || KanvasConversationStore::actsAsAi($requestingUser, $agent);

        $allowed = $ownCompanyOnly ? [] : array_values(array_intersect($requested, self::memberCompanyIds($requestingUser, $app)));

        if ($allowed === []) {
            return CompanyScope::ownCompanyOnly(
                $app,
                $ownCompany,
                $requestingUser,
                $agentId
            );
        }

        $live = Companies::query()
            ->whereIn('id', $allowed)
            ->notDeleted()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return CompanyScope::withCompanies(
            $app,
            $ownCompany,
            $live,
            $requestingUser,
            $agentId
        );
    }
}
