<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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
    public static function findUsersByArray(array $users, ?CompanyInterface $company = null): Collection
    {
        return Users::select('users.*')
            ->join('users_associated_apps', 'users_associated_apps.users_id', 'users.id')
            ->where('users_associated_apps.apps_id', app(Apps::class)->id)
            ->when($company, function ($query, $company) {
                $query->where('users_associated_apps.companies_id', $company->getKey());
            })
            ->whereIn('users.id', $users)
            ->orWhereIn('users.email', $users)
            ->groupBy('users.id')
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
    public static function getUserOfCompanyById(CompanyInterface $company, int $id): Users
    {
        try {
            return Users::select('users.*')
                ->join('users_associated_company', 'users_associated_company.users_id', 'users.id')
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
    public static function getUserOfAppById(int $id, ?AppInterface $app = null): Users
    {
        $app = $app ?? app(Apps::class);

        return Users::select('users.*')
            ->join('users_associated_apps', 'users_associated_apps.users_id', 'users.id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('users.id', $id)
            ->firstOrFail();
    }

    public static function getUsersByDaysCreated(int $days, ?AppInterface $app = null): Collection
    {
        $app = $app ?? app(Apps::class);

        return Users::join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
            ->whereRaw('DATEDIFF(CURDATE(), users_associated_apps.created_at) = ?', [$days])
            ->select('users.*')
            ->groupBy('users.id')
            ->get();
    }

    /**
     * getAll.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getAll(int $companiesId): Collection
    {
        return Users::select('users.*')
            ->join('users_associated_company', 'users_associated_company.users_id', 'users.id')
            ->where('users_associated_company.companies_id', $companiesId)
            ->whereNot('users.id', auth()->user()->id)
            ->select('users.*')
            ->get();
    }

    /**
     * User belongs / has permission in this company.
     * @psalm-suppress MixedReturnStatement
     * @throws ExceptionsModelNotFoundException
     */
    public static function belongsToCompany(Users|UserInterface $user, CompanyInterface $company): UsersAssociatedCompanies
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
    public static function belongsToCompanyBranch(Users|UserInterface $user, CompanyInterface $company, CompaniesBranches $branch): UsersAssociatedCompanies
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
    public static function belongsToThisApp(Users|UserInterface $user, Apps $app, ?CompanyInterface $company = null): UsersAssociatedApps
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
    public static function userOwnsThisApp(Users|UserInterface $user, Apps $app): UsersAssociatedApps
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
