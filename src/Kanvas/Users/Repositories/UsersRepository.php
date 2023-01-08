<?php
declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;

class UsersRepository
{
    /**
     * Get the user by email.
     *
     * @param int $email
     *
     * @return Users
     */
    public static function getById(int $id, int $companiesId) : Users
    {
        return Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
                ->where('users_associated_company.companies_id', $companiesId)
                ->where('id', $id)
                ->firstOrFail();
    }

    /**
     * getAll.
     *
     * @param  int $companiesId
     *
     * @return Users
     */
    public static function getAll(int $companiesId) : Collection
    {
        return Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
                ->where('users_associated_company.companies_id', $companiesId)
                ->whereNot('users.id', auth()->user()->id)
                ->get();
    }

    /**
     * User belongs / has permission in this company.
     *
     * @param Companies $company
     * @param Users $user
     *
     * @throws Exception
     *
     * @return UsersAssociatedCompanies
     */
    public static function belongsToCompany(Users $user, Companies $company) : UsersAssociatedCompanies
    {
        try {
            return UsersAssociatedCompanies::where('users_id', $user->getKey())
                                ->where('companies_id', $company->getKey())
                                ->where('is_deleted', StateEnums::NO->getValue())
                                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException('User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin');
        }
    }

    /**
     * User belongs / has permission in this company.
     *
     * @param Companies $company
     * @param Users $user
     *
     * @throws Exception
     *
     * @return UsersAssociatedCompanies
     */
    public static function belongsToCompanyBranch(Users $user, Companies $company, CompaniesBranches $branch) : UsersAssociatedCompanies
    {
        try {
            return UsersAssociatedCompanies::where('users_id', $user->getKey())
                                ->where('companies_id', $company->getKey())
                                ->where('companies_branches_id', $branch->getKey())
                                ->where('is_deleted', StateEnums::NO->getValue())
                                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException('User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin');
        }
    }

    /**
     * User associated to this company on the current app.
     *
     * @param Apps $app
     * @param Companies $company
     * @param Users $user
     *
     * @throws Exception
     *
     * @return UsersAssociatedApps
     */
    public static function belongsToThisApp(Users $user, Apps $app, Companies $company) : UsersAssociatedApps
    {
        try {
            return UsersAssociatedApps::where('users_id', $user->getKey())
                        ->where('apps_id', $app->getKey())
                        ->whereIn('companies_id', [AppEnums::GLOBAL_COMPANY_ID->getValue(), $company->getKey()])
                        ->where('is_deleted', StateEnums::NO->getValue())
                        ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ModelNotFoundException('User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin');
        }
    }
}
