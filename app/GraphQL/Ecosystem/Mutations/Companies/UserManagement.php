<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Auth\AuthenticationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

final class UserManagement
{
    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function addUserToBranch($rootValue, array $request): bool
    {
        $user = Users::getById($request['users_id']);
        $branch = CompaniesBranches::getById($request['id']);
        $company = $branch->company()->get()->first();

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->associateUser(
            $user,
            StateEnums::YES->getValue(),
            $branch
        );

        return true;
    }

    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function addUserToCompany($rootValue, array $request): bool
    {
        $user = Users::getById($request['users_id']);
        $company = Companies::getById($request['id']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->associateUser(
            $user,
            StateEnums::YES->getValue(),
            CompaniesBranches::getGlobalBranch()
        );

        return true;
    }

    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function removeUserFromCompany($rootValue, array $request): bool
    {
        $user = Users::getById($request['users_id']);
        $company = Companies::getById($request['id']);

        if ($company->users_id == $user->getId()) {
            throw new AuthenticationException('You can not remove yourself from the company');
        }

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->associateUser(
            $user,
            StateEnums::YES->getValue(),
            CompaniesBranches::getGlobalBranch()
        )->delete();

        return true;
    }

    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function removeUserFromBranch($rootValue, array $request): bool
    {
        $user = Users::getById($request['users_id']);
        $branch = CompaniesBranches::getById($request['id']);
        $company = $branch->company()->get()->first();

        if ($company->users_id == $user->getId()) {
            throw new AuthenticationException('You can not remove yourself from the company');
        }

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->associateUser(
            $user,
            StateEnums::YES->getValue(),
            $branch
        )->delete();

        return true;
    }
}
