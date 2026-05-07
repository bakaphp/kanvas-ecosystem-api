<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;

trait ResolvesTargetCompanyTrait
{
    /**
     * Determine the target companies_id for a create mutation based on the user's role.
     *
     * - App owner can set any companies_id, including 0 (global).
     * - Everyone else always gets their own company, regardless of what was requested.
     */
    protected function resolveTargetCompaniesId(UserInterface $user, array $request): int
    {
        $requestedCompaniesId = array_key_exists('companies_id', $request) ? (int) $request['companies_id'] : null;

        if ($user->isAppOwner() && $requestedCompaniesId !== null) {
            return $requestedCompaniesId;
        }

        return $user->getCurrentCompany()->getId();
    }

    /**
     * Patch the model's companies_id if it differs from the resolved target.
     */
    protected function applyTargetCompaniesId(Model $model, int $targetCompaniesId): void
    {
        if ($targetCompaniesId !== (int) $model->companies_id) {
            $model->companies_id = $targetCompaniesId;
            $model->saveOrFail();
        }
    }
}
