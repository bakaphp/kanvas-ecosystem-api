<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Exception;
use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Hash;
use Kanvas\AccessControlList\Enums\AbilityEnum;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\UserManagement as UserManagementService;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enum\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Notifications\Templates\ChangeEmailUserLogged;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Users\Actions\CreateInviteAction;
use Kanvas\Users\Actions\ProcessInviteAction;
use Kanvas\Users\Actions\RequestDeleteAccountAction as RequestDeleteAction;
use Kanvas\Users\DataTransferObject\CompleteInviteInput;
use Kanvas\Users\DataTransferObject\Invite as InviteDto;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Users\Repositories\UsersInviteRepository;
use Kanvas\Users\Repositories\UsersRepository;

class UserManagementMutation
{
    use HasMutationUploadFiles;

    /**
     * changePassword.
     */
    public function changePassword(mixed $root, array $req): bool
    {
        $user = UsersRepository::getByEmail(AuthFacade::user()->email);
        $user->changePassword((string) $req['current_password'], (string) $req['new_password'], app(Apps::class));
        $user->notify(new ChangePasswordUserLogged($user, ['company' => $user->getCurrentCompany()]));

        return true;
    }

    /**
     * Update user information.
     */
    public function updateUser(mixed $rootValue, array $request): Users
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $canEditUser = $user->isAdmin() || $user->can(AbilityEnum::MANAGE_USERS->value) || $user->isAppOwner();
        $userId = $canEditUser && (int) $request['id'] > 0 ? (int) $request['id'] : $user->getId();

        if ($user->isAdmin()) {
            $userToEdit = UsersRepository::getUserOfAppById($userId, $app);
        } else {
            UsersRepository::belongsToThisApp($user, $app);
            $userToEdit = $user; //UsersRepository::getUserOfCompanyById($company, (int) $userId); @todo lets wait and see if still needed
        }

        $userManagement = new UserManagementService($userToEdit, $app, $user);
        $userToEdit = $userManagement->update($request['data']);

        return $userToEdit;
    }

    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     */
    public function insertInvite($rootValue, array $request): UsersInvite
    {
        $request = $request['input'];
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $branch = isset($request['companies_branches_id']) ? CompaniesBranches::getById($request['companies_branches_id']) : auth()->user()->getCurrentBranch();

        $invite = new CreateInviteAction(
            new InviteDto(
                $app,
                $branch,
                $request['role_id'] ?? RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $company)->id,
                $request['email'],
                $request['firstname'] ?? null,
                $request['lastname'] ?? null,
                $request['description'] ?? null,
                $request['email_template'] ?? null,
                $request['custom_fields'] ?? []
            ),
            auth()->user()
        );

        return $invite->execute();
    }

    /**
     * deleteInvite.
     *
     * @param  mixed $rootValue
     */
    public function deleteInvite($rootValue, array $request): bool
    {
        $invite = UsersInviteRepository::getById(
            (int) $request['id'],
            auth()->user()->getCurrentCompany()
        );

        $invite->softDelete();

        return true;
    }

    /**
     * processInvite.
     *
     * @param  mixed $rootValue
     */
    public function getInvite($rootValue, array $request): UsersInvite
    {
        //$action = new ProcessInviteAction($request['hash'], $request['password']);
        return UsersInviteRepository::getByHash($request['hash']);
    }

    /**
     * Process User invite.
     *
     * @param  mixed $rootValue
     */
    public function process($rootValue, array $request): array
    {
        $action = new ProcessInviteAction(
            CompleteInviteInput::from($request['input'])
        );

        $user = $action->execute();

        return $user->createToken('kanvas-login')->toArray();
    }

    public function updateUserEmail(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        //sent email notification
        $updateEmail = $user->updateEmail($request['email'], $app);
        $updateEmailNotification = new ChangeEmailUserLogged($user);
        $updateEmailNotification->setFromUser($user);

        $user->notify($updateEmailNotification);

        return $updateEmail;
    }

    public function updateUserDisplayName(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $user->updateDisplayName($request['displayname'], app(Apps::class));
    }

    public function updatePhotoProfile(mixed $rootValue, array $request): Users
    {
        $loggedUser = auth()->user();
        if ($request['user_id'] != $loggedUser->getId() && ! $loggedUser->isAdmin()) {
            throw new Exception('You are not allowed to update this photo user');
        }
        $app = app(Apps::class);
        $user = UsersRepository::getUserOfAppById((int)$request['user_id'], $app);

        $filesystem = new FilesystemServices(app(Apps::class));
        $file = $request['file'];
        in_array($file->extension(), AllowedFileExtensionEnum::ONLY_IMAGES->getAllowedExtensions()) ?: throw new Exception('Invalid file format');

        $filesystemEntity = $filesystem->upload($file, $user);
        $action = new AttachFilesystemAction(
            $filesystemEntity,
            $user
        );
        $action->execute('photo');

        return $user;
    }

    public function attachFileToUser(mixed $root, array $request): Users
    {
        $app = app(Apps::class);
        $loggedUser = auth()->user();

        if ($request['id'] != $loggedUser->getId() && ! $loggedUser->isAdmin()) {
            throw new Exception('You are not allowed to update this photo user');
        }

        $userId = $request['id'] > 0 && $loggedUser->isAdmin() ? (int) $request['id'] : $loggedUser->getId();
        $user = UsersRepository::getUserOfAppById($userId, $app);

        return $this->uploadFileToEntity(
            model: $user,
            app: $app,
            user: $user,
            request: $request
        );
    }

    public function requestDeleteAccount(mixed $rootValue, array $request): bool
    {
        return (new RequestDeleteAction(app(Apps::class), auth()->user()))->execute();
    }
}
