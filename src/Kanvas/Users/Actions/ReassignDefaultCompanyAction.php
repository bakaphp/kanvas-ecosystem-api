<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;

class ReassignDefaultCompanyAction
{
    public function __construct(
        protected Users $user,
        protected Apps $app,
        protected Companies $removedCompany
    ) {
    }

    public function execute(): bool
    {
        $remainingCompanyIds = UsersAssociatedApps::where('users_id', $this->user->getId())
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->whereNotIn('companies_id', [AppEnums::GLOBAL_COMPANY_ID->getValue(), $this->removedCompany->getId()])
            ->pluck('companies_id');

        if ($remainingCompanyIds->isEmpty()) {
            return false;
        }

        if (! $this->pointsToRemovedCompany()) {
            return true;
        }

        $branch = UsersAssociatedCompanies::where('users_id', $this->user->getId())
            ->whereIn('companies_id', $remainingCompanyIds)
            ->where('companies_branches_id', '!=', 0)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->orderBy('companies_id')
            ->first();

        if ($branch !== null) {
            new SwitchCompanyBranchAction($this->user, (int) $branch->companies_branches_id)->execute();
        }

        return true;
    }

    private function pointsToRemovedCompany(): bool
    {
        $removedId = $this->removedCompany->getId();

        return (int) $this->user->get(Companies::cacheKey()) === $removedId
            || (int) $this->user->default_company === $removedId;
    }
}
