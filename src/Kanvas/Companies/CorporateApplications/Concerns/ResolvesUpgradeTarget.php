<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\DataTransferObject\UpgradeTarget;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

trait ResolvesUpgradeTarget
{
    /**
     * Both ids live in Lead custom fields, which any authenticated user can write through
     * updateLead, so they are untrusted input: the pair is only honoured when
     * users_associated_apps proves the user and the company both belong to this app.
     */
    protected function upgradeTarget(Model $application, Apps $app): ?UpgradeTarget
    {
        $companyId = (int) Field::COMPANY_ID->readFrom($application);
        $userId = (int) Field::UPGRADE_USER_ID->readFrom($application);

        if ($companyId === 0 || $userId === 0) {
            return null;
        }

        $belongsToApp = UsersAssociatedApps::query()
            ->where('users_id', $userId)
            ->where('companies_id', $companyId)
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->exists();

        if (! $belongsToApp) {
            return null;
        }

        $company = Companies::query()->where('id', $companyId)->notDeleted()->first();

        return $company === null ? null : new UpgradeTarget($company, Users::getById($userId));
    }
}
