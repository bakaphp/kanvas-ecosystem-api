<?php

declare(strict_types=1);

namespace Kanvas\Companies\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\UserCompanyApps;
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
     * Get by uuid and app.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByUuid(string $uuid, ?Apps $app = null, ?Users $user = null): Companies
    {
        return Companies::where('uuid', $uuid)
               ->where('companies.is_deleted', StateEnums::NO->getValue())
               ->when($app, function ($query, $app) {
                   $query->join(
                       'user_company_apps',
                       'user_company_apps.companies_id',
                       '=',
                       'companies.id'
                   );
                   $query->where('user_company_apps.apps_id', $app->getId());
               })->when($user, function ($query, $user) {
                   $query->join(
                       'users_associated_company',
                       'users_associated_company.companies_id',
                       '=',
                       'companies.id'
                   );
                   $query->where('users_associated_company.users_id', $user->getId());
               })->select('companies.*')->firstOrFail();
    }

    /**
     * User belongs / has permission in this company.
     * @psalm-suppress MixedReturnStatement
     *
     * @throws ExceptionsModelNotFoundException
     */
    public static function userAssociatedToCompany(Companies $company, Users $user): UsersAssociatedCompanies
    {
        if ($user->isAppOwner()) {
            return new UsersAssociatedCompanies();
        }

        try {
            return UsersAssociatedCompanies::where('users_id', $user->getKey())
                                ->where('companies_id', $company->getKey())
                                ->where('is_deleted', StateEnums::NO->getValue())
                                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException('User doesn\'t belong to this company ' . $company->id . ' , talk to the Admin');
        }
    }

    /**
     * User belongs / has permission in this company.
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function userAssociatedToCompanyAndBranch(Companies $company, CompaniesBranches $branch, Users $user): UsersAssociatedCompanies
    {
        if ($user->isAppOwner()) {
            return new UsersAssociatedCompanies();
        }

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

    public static function getAllCompanyUsers(Companies $company): Collection
    {
        return self::getAllCompanyUserBuilder($company)->get();
    }

    public static function getAllCompanyUserBuilder(Companies $company): Builder
    {
        $ecosystemConnection = config('database.connections.ecosystem');
        // $columns = Schema::Connection('ecosystem')->getColumnListing('users');

        return UsersAssociatedCompanies::join($ecosystemConnection['database'] . '.users', 'users.id', '=', 'users_associated_company.users_id')
                                ->where('companies_id', $company->getKey())
                                ->where('users_associated_company.is_deleted', StateEnums::NO->getValue())
                                ->where('users.is_deleted', StateEnums::NO->getValue())
                              //  ->groupBy($columns)
                                ->select('users.*');
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

    public static function getCompanyByNameAndApp(string $name, AppInterface $app): ?Companies
    {
        return Companies::join('user_company_apps', 'companies.id', '=', 'user_company_apps.companies_id')
            ->where('companies.name', $name)
            ->where('user_company_apps.apps_id', $app->getId())
            ->where('companies.is_deleted', 0)
            ->where('user_company_apps.is_deleted', 0)
            ->select('companies.*')
            ->first();
    }

    public static function hasAccessToThisApp(CompanyInterface $company, AppInterface $app): bool
    {
        $exist = UserCompanyApps::where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->exists();

        if (! $exist) {
            throw new ExceptionsModelNotFoundException('Company doesn\'t have access to this app');
        }

        return true;
    }
}
