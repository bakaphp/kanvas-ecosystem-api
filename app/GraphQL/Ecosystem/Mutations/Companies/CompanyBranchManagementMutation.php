<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Branches\Actions\CreateCompanyBranchActions;
use Kanvas\Companies\Branches\Actions\DeleteCompanyBranchActions;
use Kanvas\Companies\Branches\Actions\UpdateCompanyBranchActions;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPostData;
use Kanvas\Companies\Branches\DataTransferObject\CompaniesBranchPutData;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Users\Models\Users;

class CompanyBranchManagementMutation
{
    use HasMutationUploadFiles;

    /**
     * createCompaniesBranch
     *
     * @param  array $req
     */
    public function createCompaniesBranch(mixed $root, array $request): CompaniesBranches
    {
        $request['input']['users_id'] = Auth::user()->getKey();
        $dto = CompaniesBranchPostData::fromArray($request['input']);
        $action = new CreateCompanyBranchActions(Auth::user(), $dto);

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
        $action = new UpdateCompanyBranchActions(Auth::user(), $dto);

        return $action->execute((int) $request['id']);
    }

    public function attachFileToCompany(mixed $root, array $request): CompaniesBranches
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $companyBranch = CompaniesBranches::getById((int) $request['id']);

        $company = $companyBranch->company()->first();

        CompaniesRepository::userAssociatedToCompanyAndBranch($company, $companyBranch, $user);

        return $this->uploadFileToEntity(
            model: $companyBranch,
            app: $app,
            user: $user,
            request: $request
        );
    }

    public function updatePhotoProfile(mixed $root, array $request): CompaniesBranches
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $companyBranch = CompaniesBranches::getById((int) $request['id']);

        $company = $companyBranch->company()->first();

        CompaniesRepository::userAssociatedToCompanyAndBranch($company, $companyBranch, $user);

        $filesystem = new FilesystemServices(app(Apps::class));
        $file = $request['file'];
        in_array($file->extension(), ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp']) ?: throw new Exception('Invalid file format');

        $filesystemEntity = $filesystem->upload($file, $user);

        $action = new AttachFilesystemAction(
            $filesystemEntity,
            $companyBranch
        );
        $action->execute('photo');

        return $companyBranch;
    }

    /**
     * deleteCompanyBranch
     */
    public function deleteCompanyBranch(mixed $root, array $request): bool
    {
        /**
         * @todo only super admin can do this
         */
        $companyBranchDelete = new DeleteCompanyBranchActions(Auth::user());

        return $companyBranchDelete->execute((int) $request['id']) instanceof CompaniesBranches;
    }

    /**
     * add user to branch.
     */
    public function addUserToBranch($rootValue, array $request): bool
    {
        $user = Users::getById($request['user_id']);
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

        $branch->set('total_users', $branch->users()->count());

        return true;
    }

    /**
     * remove user from branch.
     *
     * @param  mixed $rootValue
     * @todo We need to REMOVE the branch key from cache.
     */
    public function removeUserFromBranch($rootValue, array $request): bool
    {
        $user = Users::getById($request['user_id']);
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
