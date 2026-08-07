<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Collection;
use Kanvas\Users\Models\Users;

/**
 * Resolve users of the CURRENT company (via users_associated_company) by partial name or email, so a
 * tool can only ever assign work to a member of the tenant — never a user outside it. Requires the
 * host tool to expose $this->company (see HasKanvasContext).
 */
trait ResolvesCompanyUserForTool
{
    /**
     * @return Collection<int, Users>
     */
    protected function resolveCompanyUsers(string $term): Collection
    {
        $like = '%' . $term . '%';

        return Users::query()
            ->select('users.*')
            ->join('users_associated_company', 'users_associated_company.users_id', '=', 'users.id')
            ->where('users_associated_company.companies_id', $this->company->getId())
            ->where(
                // CONCAT match lets "firstname lastname" resolve as a single query term.
                fn ($q) => $q->where('users.firstname', 'like', $like)
                    ->orWhere('users.lastname', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhereRaw("CONCAT(users.firstname, ' ', users.lastname) like ?", [$like]),
            )
            ->distinct()
            ->limit(10)
            ->get();
    }
}
