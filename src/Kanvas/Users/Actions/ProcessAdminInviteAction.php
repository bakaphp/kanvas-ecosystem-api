<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\DataTransferObject\CompleteInviteInput;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\AdminInviteRepository;
use Throwable;

class ProcessAdminInviteAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected CompleteInviteInput $adminInvite,
        protected ?Users $user = null
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Users
    {
        $invite = AdminInviteRepository::getByHash($this->adminInvite->getInviteHash());

        $dto = RegisterPostDataDto::fromArray([
            'email' => $invite->email,
            'password' => $this->adminInvite->password,
            'firstname' => $this->adminInvite->firstname,
            'lastname' => $this->adminInvite->lastname ?? '',
            'phone_number' => $this->adminInvite->phone_number ?? null,
        ]);

        DB::beginTransaction();

        try {
            $user = $this->user ?? (new CreateUserAction($dto))->execute();
            $app = $invite->app;
            $company = $user->getCurrentCompany();

            $appDefault = Apps::getByUuid(config('kanvas.app.id'));
            $appDefault->associateUser(
                user: $user,
                isActive: StateEnums::YES->getValue()
            );

            $company->associateUserApp(
                user: $user,
                app: $appDefault,
                isActive: StateEnums::YES->getValue()
            );

            //create user admin key
            (new CreateAppKeyAction(
                data: new AppKeyInput(
                    $app->name . ' ' . $user->displayname . ' Key',
                    $app,
                    $user
                ),
                createUserInApp: false
            ))->execute();

            //use action instead of $app to save profile data.
            (new RegisterUsersAppAction($user, $app))->execute($user->password);

            //Set password to null to avoid auto-assign.
            $user->password = null;

            $company->associateUserApp(
                user: $user,
                app: $app,
                isActive: StateEnums::YES->getValue()
            );

            $invite->softDelete();
            $app->update(); //clear cache
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $user;
    }
}
