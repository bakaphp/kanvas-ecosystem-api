<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
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
        protected CompleteInviteInput $adminInvite
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
            $user = (new CreateUserAction($dto))->execute();
            $app = $invite->app()->get()->first();
            $company = $user->companies()->get()->first();

            $appDefault = Apps::getByUuid(env('KANVAS_APP_ID'));
            $appDefault->associateUser(
                user: $user,
                isActive: StateEnums::YES->getValue()
            );

            $company->associateUserApp(
                user: $user,
                app: $appDefault,
                isActive: StateEnums::YES->getValue()
            );

            //Set password to null to avoid auto-assign.
            $user->password = null;

            //associate admin to global company
            $app->associateUser(
                user: $user,
                isActive: StateEnums::YES->getValue()
            );

            $company->associateUserApp(
                user: $user,
                app: $app,
                isActive: StateEnums::YES->getValue()
            );

            $invite->softDelete();
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $user;
    }
}
