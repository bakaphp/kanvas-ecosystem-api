<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Collection;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

/**
 * Resolve users of the CURRENT company (via users_associated_company) by partial name or email, so a
 * tool can only ever assign work to a member of the tenant — never a user outside it. Requires the
 * host tool to expose $this->company (see HasKanvasContext).
 */
trait ResolvesCompanyUserForTool
{
    use MatchesNameTerms;

    /**
     * @return Collection<int, Users>
     */
    protected function resolveCompanyUsers(string $term): Collection
    {
        $query = Users::query()
            ->select('users.*')
            ->join('users_associated_company', 'users_associated_company.users_id', '=', 'users.id')
            ->where('users_associated_company.companies_id', $this->company->getId())
            ->where('users_associated_company.is_deleted', StateEnums::NO->getValue());

        return $this->scopeToNameMatch(
            $query,
            [
                'users.firstname',
                'users.lastname',
                'users.displayname',
                'users.email',
            ],
            $term,
        )
            ->distinct()
            ->limit(10)
            ->get();
    }
}
