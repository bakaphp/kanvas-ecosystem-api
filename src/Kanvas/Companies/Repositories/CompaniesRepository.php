<?php

declare(strict_types=1);

namespace Kanvas\Companies\Repositories;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;

class CompaniesRepository
{
    /**
     * Get company by Id.
     * @psalm-suppress MixedReturnStatement
     *
     * @throws ModelNotFoundException
     */
    public static function getById(int $id): Companies
    {
        return Companies::where('id', $id)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->firstOrFail();
    }

    /**
     * Get by uuid.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByUuid(string $uuid): Companies
    {
        return Companies::where('uuid', $uuid)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->firstOrFail();
    }

    /**
     * User belongs / has permission in this company.
     * @psalm-suppress MixedReturnStatement
     *
     * @throws ExceptionsModelNotFoundException
     */
    public static function userAssociatedToCompany(Companies $company, Users $user): UsersAssociatedCompanies
    {
        try {
            return UsersAssociatedCompanies::where('users_id', $user->getKey())
                                ->where('companies_id', $company->getKey())
                                ->where('is_deleted', StateEnums::NO->getValue())
                                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException('User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin');
        }
    }

    /**
     * User belongs / has permission in this company.
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function userAssociatedToCompanyAndBranch(Companies $company, CompaniesBranches $branch, Users $user): UsersAssociatedCompanies
    {
        try {
            return UsersAssociatedCompanies::where('users_id', $user->getKey())
                                ->where('companies_id', $company->getKey())
                                ->whereIn('companies_branches_id', [$branch->getKey(), StateEnums::NO->getValue()])
                                ->where('is_deleted', AppEnums::GLOBAL_COMPANY_ID->getValue())
                                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException('User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin');
        }
    }

    /**
     * User associated to this company on the current app.
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function userAssociatedToCompanyInThisApp(Apps $app, Companies $company, Users $user): UsersAssociatedApps
    {
        try {
            return UsersAssociatedApps::where('users_id', $user->getKey())
                        ->where('apps_id', $app->getKey())
                        ->where('companies_id', $company->getKey())
                        ->where('is_deleted', StateEnums::NO->getValue())
                        ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException('User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin');
        }
    }
}
