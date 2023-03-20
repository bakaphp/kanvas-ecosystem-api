<?php

declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Branches\Actions\CreateCompanyBranchActions;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Branches\Actions\UpdateCompanyBranchActions;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;
use Kanvas\Companies\Branches\Actions\DeleteCompanyBranchActions;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Actions\DeleteCompaniesAction;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Enums\StateEnums;
use Illuminate\Auth\AuthenticationException;

class CompaniesManagementMutation
{
    /**
     * createCompany
     *
     * @param  mixed $root
     * @param  array $request
     * @return Companies
     */
    public function createCompany(mixed $root, array $request): Companies
    {
        $request['input']['users_id'] = Auth::user()->getKey();
        $dto = CompaniesPostData::fromArray($request['input']);
        $action = new  CreateCompaniesAction($dto);
        return $action->execute();
    }

    /**
     * updateCompany
     *
     * @param  mixed $root
     * @param  array $request
     * @return Companies
     */
    public function updateCompany(mixed $root, array $request): Companies
    {
        $dto = CompaniesPutData::fromArray($request['input']);
        $action = new UpdateCompaniesAction(Auth::user(), $dto);
        return $action->execute($request['id']);
    }

    /**
     * deleteCompany
     *
     * @param  mixed $root
     * @param  array $request
     * @return bool
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
     * @param  mixed $root
     * @param  array $req
     * @return CompaniesBranches
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
     * @param  mixed $root
     * @param  array $req
     * @return CompaniesBranches
     */
    public function updateCompanyBranch(mixed $root, array $request): CompaniesBranches
    {
        $dto = CompaniesBranchPutData::fromArray($request['input']);
        $action = new  UpdateCompanyBranchActions(Auth::user(), $dto);
        return $action->execute($request['id']);
    }

    /**
     * deleteCompanyBranch
     *
     * @param  mixed $root
     * @param  array $request
     * @return string
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
