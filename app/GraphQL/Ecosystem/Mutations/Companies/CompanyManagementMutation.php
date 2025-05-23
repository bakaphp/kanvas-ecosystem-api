<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Companies;

use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Actions\DeactivateUserAction;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Jobs\DeleteCompanyJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesAddress;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Services\SetupService;
use Kanvas\Users\Actions\AssignRoleAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

class CompanyManagementMutation
{
    use HasMutationUploadFiles;

    /**
     * createCompany
     */
    public function createCompany(mixed $root, array $request): Companies
    {
        if (! auth()->user()->isAdmin()) {
            throw new AuthorizationException('Only admin can create companies, please contact your admin');
        }

        if (auth()->user()->isAdmin() && key_exists('users_id', $request['input'])) {
            $user = Users::getById($request['input']['users_id']);
            UsersRepository::belongsToThisApp($user, app(Apps::class)) ;
        } else {
            $user = auth()->user();
        }
        $dto = Company::viaRequest($request['input'], $user);
        $company = (new CreateCompaniesAction($dto))->execute();

        (new SetupService())->onBoarding(
            $user,
            app(Apps::class),
            $company
        );

        return $company;
    }

    /**
     * @todo move to service ?
     * updateCompany
     */
    public function updateCompany(mixed $root, array $request): Companies
    {
        $company = Companies::getById((int) $request['id']);

        $company->hasCompanyPermission(auth()->user());

        if (auth()->user()->isAdmin() && key_exists('users_id', $request['input'])) {
            $user = Users::getById($request['input']['users_id']);
            UsersRepository::belongsToThisApp($user, app(Apps::class), $company) ;
        } else {
            $user = auth()->user();
        }

        $dto = Company::viaRequest($request['input'], $user);
        $action = new UpdateCompaniesAction($company, $user, $dto);

        return $action->execute();
    }

    public function attachFileToCompany(mixed $root, array $request): Companies
    {
        $app = app(Apps::class);
        $company = Companies::getById((int) $request['id']);

        $company->hasCompanyPermission(auth()->user());

        return $this->uploadFileToEntity(
            model: $company,
            app: $app,
            user: auth()->user(),
            request: $request
        );
    }

    public function updatePhotoProfile(mixed $root, array $request): Companies
    {
        $company = Companies::getById($request['id']);

        $company->hasCompanyPermission(auth()->user());

        if (! auth()->user()->isAdmin()) {
            $company = Companies::getById($request['id']);
            CompaniesRepository::userAssociatedToCompany(
                $company,
                auth()->user()
            );
        }
        $filesystem = new FilesystemServices(app(Apps::class));
        $file = $request['file'];
        in_array($file->extension(), AllowedFileExtensionEnum::ONLY_IMAGES->getAllowedExtensions()) ?: throw new Exception('Invalid file format');

        $filesystemEntity = $filesystem->upload($file, auth()->user());
        $action = new AttachFilesystemAction(
            $filesystemEntity,
            $company
        );
        $action->execute('photo');

        return $company;
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

        if (! auth()->user()->isAdmin()) {
            throw new AuthorizationException('Only admin can delete companies, please contact your admin');
        }

        DeleteCompanyJob::dispatch((int) $request['id'], Auth::user(), app(Apps::class));

        return true;
    }

    public function addUserToCompany($rootValue, array $request): bool
    {
        $user = Users::getById($request['user_id']);
        $company = Companies::getById($request['id']);
        $app = app(Apps::class);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->hasCompanyPermission(auth()->user());

        $branch = app(CompaniesBranches::class);

        $companyDefaultBranch = $company->defaultBranch()->first();

        //this happens if they we dont get a branch for via header for the current company (frontend needs to fix)
        if ($branch->companies_id != $company->getId() && $companyDefaultBranch) {
            $branch = $companyDefaultBranch;
        }

        DB::transaction(function () use ($user, $company, $branch, $request, $app) {
            $company->associateUser(
                $user,
                StateEnums::YES->getValue(),
                CompaniesBranches::getGlobalBranch(),
                (int) ($request['rol_id'] ?? null)
            );

            if (is_object($branch)) {
                $company->associateUser(
                    $user,
                    StateEnums::YES->getValue(),
                    $branch,
                    (int) ($request['rol_id'] ?? null)
                );
            }

            $company->associateUserApp(
                $user,
                app(Apps::class),
                StateEnums::YES->getValue(),
                (int) ($request['rol_id'] ?? null)
            );

            //@todo this is a legacy role and should be removed
            $assignLegacyRole = new AssignRoleAction(
                $user,
                $company,
                $app
            );
            $assignLegacyRole->execute('Admins');
        });

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

        $company->hasCompanyPermission(auth()->user());

        $branch = app(CompaniesBranches::class);

        $companyDefaultBranch = $company->defaultBranch()->first();

        //this happens if they we dont get a branch for via header for the current company (frontend needs to fix)
        if ($branch->companies_id != $company->getId() && $companyDefaultBranch) {
            $branch = $companyDefaultBranch;
        }

        if (is_object($branch)) {
            DB::transaction(function () use ($user, $company, $branch) {
                $baseConditions = [
                    ['users_id', '=', $user->getKey()],
                    ['companies_id', '=', $company->getKey()],
                ];

                // Delete the specific branch association
                UsersAssociatedCompanies::where($baseConditions)
                    ->where('companies_branches_id', '=', $branch->getKey())
                    ->delete();

                // Check if there are no other branches associated (except the "0" branch)
                $hasOtherBranches = UsersAssociatedCompanies::where($baseConditions)
                    ->where('companies_branches_id', '!=', 0)
                    ->exists();

                if (! $hasOtherBranches) {
                    // Delete the "0" branch association
                    UsersAssociatedCompanies::where($baseConditions)
                        ->where('companies_branches_id', '=', 0)
                        ->delete();

                    // Assuming getAppId() is a method you have available to get the app's ID
                    $app = app(Apps::class);

                    // Delete associated apps
                    UsersAssociatedApps::where($baseConditions)
                        ->where('apps_id', '=', $app->getId())
                        ->delete();

                    (new DeactivateUserAction($user, $app))->execute();
                }
            });

            return true;
        }

        return false;
    }

    public function addAddressToCompany($rootValue, array $request): CompaniesAddress
    {
        $company = Companies::getById($request['id']);
        $addressInput = $request['input'];
        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $company->hasCompanyPermission(auth()->user());

        $addressData = [
            'fullname' => $addressInput['fullname'] ?? '',
            'phone' => $addressInput['phone'] ?? '',
            'companies_id' => $company->getId(),
            'address' => $addressInput['address'],
            'address_2' => $addressInput['address_2'] ?? '',
            'city' => $addressInput['city'] ?? '',
            'county' => $addressInput['county'] ?? '',
            'state' => $addressInput['state'] ?? '',
            'zip' => $addressInput['zip'] ?? '',
            'city_id' => $addressInput['city_id'] ?? 0,
            'state_id' => $addressInput['state_id'] ?? 0,
            'countries_id' => $addressInput['country_id'] ?? 0,
            'is_default' => $addressInput['is_default'] ?? false,
        ];

        if (isset($addressInput['is_default']) && $addressInput['is_default']) {
            $company->addresses()->update(['is_default' => false]);
        }

        $address = $company->addresses()->create($addressData);

        return $address;
    }

    public function updateCompanyAddress($rootValue, array $request): CompaniesAddress
    {
        $company = Companies::getById($request['id']);
        $address = CompaniesAddress::getById($request['address_id']);
        $addressInput = $request['input'];

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );


        if (! $address->is_default && isset($addressInput['is_default']) && $addressInput['is_default']) {
            $company->addresses()->update(['is_default' => false]);
        }

        $address->update([
            'fullname' => $addressInput['fullname'] ?? $address->fullname,
            'phone' => $addressInput['phone'] ?? $address->phone,
            'companies_id' => $company->getId(),
            'address' => $addressInput['address'] ?? $address->address,
            'address_2' => $addressInput['address_2'] ?? $address->address_2,
            'city' => $addressInput['city'] ?? $address->city,
            'county' => $addressInput['county'] ?? $address->county,
            'state' => $addressInput['state'] ?? $address->state,
            'zip' => $addressInput['zip'] ?? $address->zip,
            'city_id' => $addressInput['city_id'] ?? $address->city_id,
            'state_id' => $addressInput['state_id'] ?? $address->state_id,
            'countries_id' => $addressInput['country_id'] ?? $address->countries_id,
            'is_default' => $addressInput['is_default'] ?? $address->is_default,
        ]);

        return $address;
    }

    public function removeAddressFromCompany($rootValue, array $request): bool
    {
        $company = Companies::getById($request['id']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $address = CompaniesAddress::getById($request['address_id']);
        $address->delete();

        return true;
    }
}
