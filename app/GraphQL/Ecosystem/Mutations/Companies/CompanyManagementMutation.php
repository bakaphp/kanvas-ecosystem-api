<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Jobs\DeleteCompanyJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Exception;

class CompanyManagementMutation
{
    /**
     * createCompany
     */
    public function createCompany(mixed $root, array $request): Companies
    {
        if (auth()->user()->isAdmin() && key_exists('users_id', $request['input'])) {
            $user = Users::getById($request['input']['users_id']);
            UsersRepository::belongsToThisApp($user, app(Apps::class)) ;
            $request['input']['users_id'] = $user->getKey();
        } else {
            $request['input']['users_id'] = Auth::user()->getKey();
        }
        $dto = CompaniesPostData::fromArray($request['input']);
        $action = new CreateCompaniesAction($dto);

        return $action->execute();
    }

    /**
     * updateCompany
     */
    public function updateCompany(mixed $root, array $request): Companies
    {
        if (auth()->user()->isAdmin() && key_exists('users_id', $request['input'])) {
            $user = Users::getById($request['input']['users_id']);
            UsersRepository::belongsToThisApp($user, app(Apps::class)) ;
            $request['input']['users_id'] = $user->getKey();
        } else {
            $request['input']['users_id'] = Auth::user()->getKey();
            $user = Auth::user();
        }
        $dto = CompaniesPutData::fromArray($request['input']);
        $action = new UpdateCompaniesAction($user, $dto);

        return $action->execute((int) $request['id']);
    }

    /**
     * deleteCompany
     */
    public function deleteCompany(mixed $root, array $request): bool
    {
        /**
         * @todo only super admin can do this
         */
        if (Users::where('default_company', $request['id'])->count()) {
            throw new Exception('You can not delete a company that has users associated');
        }
        DeleteCompanyJob::dispatch((int) $request['id'], Auth::user(), app(Apps::class));

        return true;
    }

    public function addUserToCompany($rootValue, array $request): bool
    {
        $user = Users::getById($request['user_id']);
        $company = Companies::getById($request['id']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->associateUser(
            $user,
            StateEnums::YES->getValue(),
            CompaniesBranches::getGlobalBranch(),
            (int)$request['rol_id'] ?? null
        );

        return true;
    }

    public function removeUserFromCompany($rootValue, array $request): bool
    {
        $user = Users::getById($request['user_id']);
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
}
