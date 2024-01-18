<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Actions\DeleteCompaniesAction;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\Branches\Actions\CreateCompanyBranchActions;
use Kanvas\Companies\Branches\Actions\DeleteCompanyBranchActions;
use Kanvas\Companies\Branches\Actions\UpdateCompanyBranchActions;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class CompaniesManagementMutation
{
    /**
     * createCompany
     */
    public function createCompany(mixed $root, array $request): Companies
    {
        if (auth()->user()->isAn((string) DefaultRoles::ADMIN->getValue()) && key_exists('users_id', $request['input'])) {
            $request['input']['users_id'] = $request['input']['users_id'] && UsersRepository::isUserInApp($request['input']['users_id']) ? $request['input']['users_id'] : Auth::user()->getKey();
        } else {
            $request['input']['users_id'] = Auth::user()->getKey();
        }
        $dto = CompaniesPostData::fromArray($request['input']);
        $action = new  CreateCompaniesAction($dto);

        return $action->execute();
    }

    /**
     * updateCompany
     */
    public function updateCompany(mixed $root, array $request): Companies
    {
        if (auth()->user()->isAn((string) DefaultRoles::ADMIN->getValue()) && key_exists('users_id', $request['input'])) {
            $request['input']['users_id'] = $request['input']['users_id'] && UsersRepository::isUserInApp($request['input']['users_id']) ? $request['input']['users_id'] : Auth::user()->getKey();
        } else {
            $request['input']['users_id'] = Auth::user()->getKey();
        }
        $dto = CompaniesPutData::fromArray($request['input']);
        $action = new UpdateCompaniesAction(Auth::user(), $dto);

        return $action->execute($request['id']);
    }

    /**
     * deleteCompany
     */
    public function deleteCompany(mixed $root, array $request): bool
    {
        /**
         * @todo only super admin can do this
         */
        $companyDelete = new DeleteCompaniesAction(Auth::user());
        $companyDelete->execute($request['id']);

        return true;
    }

    /**
     * createCompaniesBranch
     *
     * @param  array $req
     */
    public function createCompaniesBranch(mixed $root, array $request): CompaniesBranches
    {
        $request['input']['users_id'] = Auth::user()->getKey();
        $dto = CompaniesBranchPostData::fromArray($request['input']);
        $action = new  CreateCompanyBranchActions(Auth::user(), $dto);

        return $action->execute();
    }

    /**
     * updateCompanyBranch
     *
     * @param  array $req
     */
    public function updateCompanyBranch(mixed $root, array $request): CompaniesBranches
    {
        $dto = CompaniesBranchPutData::fromArray($request['input']);
        $action = new  UpdateCompanyBranchActions(Auth::user(), $dto);

        return $action->execute($request['id']);
    }

    /**
     * deleteCompanyBranch
     */
    public function deleteCompanyBranch(mixed $root, array $request): string
    {
        /**
         * @todo only super admin can do this
         */
        $companyBranchDelete = new DeleteCompanyBranchActions(Auth::user());
        $branch = $companyBranchDelete->execute($request['id']);

        return 'Successfully Delete Company Branch : ' . $branch->name;
    }

    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
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
     * @todo We need to REMOVE the company key from cache.
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
     * @todo We need to REMOVE the branch key from cache.
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
