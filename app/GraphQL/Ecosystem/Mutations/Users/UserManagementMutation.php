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
use Kanvas\Auth\Socialite\DataTransferObject\User;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Enums\AllowedFileExtensionEnum;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Notifications\Templates\ChangeEmailUserLogged;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Users\Actions\CreateAdminInviteAction;
use Kanvas\Users\Actions\CreateInviteAction;
use Kanvas\Users\Actions\ProcessAdminInviteAction;
use Kanvas\Users\Actions\ProcessInviteAction;
use Kanvas\Users\Actions\RequestDeleteAccountAction as RequestDeleteAction;
use Kanvas\Users\Actions\SaveUserAppPreferencesAction;
use Kanvas\Users\DataTransferObject\AdminInvite as AdminInviteDto;
use Kanvas\Users\DataTransferObject\CompleteInviteInput;
use Kanvas\Users\DataTransferObject\Invite as InviteDto;
use Kanvas\Users\Models\AdminInvite;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Users\Repositories\AdminInviteRepository;
use Kanvas\Users\Repositories\UsersInviteRepository;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Users\Services\UserContactsService;

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
     */
    public function insertUserInvite($rootValue, array $request): UsersInvite
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
     * insertAdminInvite.
     *
     */
    public function insertAdminInvite($rootValue, array $request): AdminInvite
    {
        $request = $request['input'];
        $app = app(Apps::class);
        $appDefault = Apps::getByUuid(config('kanvas.app.id'));

        $userAssociation = UsersAssociatedApps::where('email', $request['email'])
            ->fromApp($appDefault)
            ->notDeleted()
            ->first();

        $invite = new CreateAdminInviteAction(
            new AdminInviteDto(
                app: $app,
                email: $request['email'],
                firstname: $request['firstname'] ?? null,
                lastname: $request['lastname'] ?? null,
                description: $request['description'] ?? null,
                customFields: $request['custom_fields'] ?? []
            ),
            auth()->user(),
            (bool) $userAssociation
        );

        $invite = $invite->execute();

        if ($userAssociation) {
            (new ProcessAdminInviteAction(
                new CompleteInviteInput(
                    invite_hash: $invite->invite_hash,
                    password: $userAssociation->password,
                    firstname: $invite->firstname
                ),
                $userAssociation->user
            ))->execute();
        }

        return $invite;
    }

    /**
     * deleteInvite.
     *
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
     * deleteInvite.
     *
     */
    public function deleteAdminInvite($rootValue, array $request): bool
    {
        $invite = AdminInviteRepository::getById(
            id: (int) $request['id'],
            app: app(Apps::class)
        );

        $invite->softDelete();

        return true;
    }

    /**
     * processInvite.
     *
     */
    public function getInvite($rootValue, array $request): UsersInvite
    {
        //$action = new ProcessInviteAction($request['hash'], $request['password']);
        return UsersInviteRepository::getByHash($request['hash']);
    }

    /**
     * Process User invite.
     *
     */
    public function process($rootValue, array $request): array
    {
        $action = new ProcessInviteAction(
            CompleteInviteInput::from($request['input'])
        );

        $user = $action->execute();

        return $user->createToken('kanvas-login')->toArray();
    }

    public function processAdmin($rootValue, array $request): array
    {
        $action = new ProcessAdminInviteAction(
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

    public function checkUsersContactsMatch(mixed $rootValue, array $request): ?array
    {
        $authUser = auth()->user();
        $app = app(Apps::class);
        $contacts = $request['contacts'];
        $contactsEmails = [];
        foreach (UserContactsService::extractEmailsFromContactsList($contacts) as $email) {
            $contactsEmails[] = $email;
        }

        $appUsers = UsersAssociatedApps::where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->whereNotNull('email')
            ->whereNotIn('email', [$authUser->email])
            ->with('user')
            ->lazy();


        $contactsEmails = array_flip($contactsEmails);
        $matchingContacts = [];

        // Efficient lookup using isset()
        foreach ($appUsers as $appUser) {
            if (isset($contactsEmails[$appUser->email])) {
                $matchingContacts[] = $appUser->user;
            }
        }

        // Return alse the contacts that are not in the app
        return [
            "matching_contacts" => $matchingContacts,
            "nonmatching_contacts" => array_diff_key($contactsEmails, array_flip($matchingContacts))
        ];
    }

    public function saveUserAppPreferences(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $preferences = $request['preferences'];

        (new SaveUserAppPreferencesAction(
            user: $user,
            app: $app,
            preferences: $preferences
        ))->execute();

        return true;
    }
}
