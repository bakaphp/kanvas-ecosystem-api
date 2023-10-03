<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

class UsersRepository
{
    /**
     * findUsersByIds
     * @psalm-suppress MixedReturnStatement
     */
    public static function findUsersByIds(array $usersIds): Collection
    {
        return Users::whereHas('apps', function (Builder $query) {
            $query->where('apps_id', app(Apps::class)->id);
        })->whereIn('users.id', $usersIds)
        ->get();
    }

    /**
     * Get the user by id.
     */
    public static function getById(int $id, int $companiesId): Users
    {
        return self::getUserOfCompanyById(
            Companies::getById($companiesId),
            $id
        );
    }

    /**
     * Get the user by email.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByEmail(string $email): Users
    {
        return Users::where('email', $email)
                ->firstOrFail();
    }

    /**
     * Get the user if he exist in the current company.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getUserOfCompanyById(Companies $company, int $id): Users
    {
        try {
            return Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
                ->where('users_associated_company.companies_id', $company->getKey())
                ->where('users.id', $id)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException(
                'User not found'
            );
        }
    }

    /**
     * Get the user if he exist in the current app.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getUserOfAppById(int $id): Users
    {
        return Users::join('users_associated_apps', 'users_associated_apps.users_id', 'users.id')
            ->where('users_associated_apps.apps_id', app(Apps::class)->id)
            ->where('users.id', $id)
            ->firstOrFail();
    }

    /**
     * getAll.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getAll(int $companiesId): Collection
    {
        return Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
            ->where('users_associated_company.companies_id', $companiesId)
            ->whereNot('users.id', auth()->user()->id)
            ->get();
    }

    /**
     * User belongs / has permission in this company.
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function belongsToCompany(Users $user, Companies $company): UsersAssociatedCompanies
    {
        try {
            return UsersAssociatedCompanies::where('users_id', $user->getKey())
                ->where('companies_id', $company->getKey())
                ->where('is_deleted', StateEnums::NO->getValue())
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException(
                'User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin'
            );
        }
    }

    /**
     * User belongs / has permission in this company.
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function belongsToCompanyBranch(Users $user, Companies $company, CompaniesBranches $branch): UsersAssociatedCompanies
    {
        try {
            return UsersAssociatedCompanies::where('users_id', $user->getKey())
                ->where('companies_id', $company->getKey())
                ->where('companies_branches_id', $branch->getKey())
                ->where('is_deleted', StateEnums::NO->getValue())
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException(
                'User doesn\'t belong to this company ' . $company->uuid . ' , talk to the Admin'
            );
        }
    }

    /**
     * User associated to this company on the current app.
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function belongsToThisApp(Users $user, Apps $app, ?Companies $company = null): UsersAssociatedApps
    {
        try {
            $query = UsersAssociatedApps::where('users_id', $user->getKey())
                ->where('apps_id', $app->getKey())
                ->where('is_deleted', StateEnums::NO->getValue())
                ->when($company, function ($query, $company) {
                    $query->where('companies_id', $company->getKey());
                });

            return $query->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException(
                'User doesn\'t belong to this app ' . $app->name . ', talk to the Admin'
            );
        }
    }

    /**
     * Is this user owner of the app?
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function userOwnsThisApp(Users $user, Apps $app): UsersAssociatedApps
    {
        try {
            //for now user who own / created the app have global company id assign the them
            return UsersAssociatedApps::where('users_id', $user->getKey())
                ->where('apps_id', $app->getKey())
                ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->where('is_deleted', StateEnums::NO->getValue())
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ExceptionsModelNotFoundException(
                'User doesn\'t own this app ' . $app->uuid . ' , talk to the Admin'
            );
        }
    }
}
