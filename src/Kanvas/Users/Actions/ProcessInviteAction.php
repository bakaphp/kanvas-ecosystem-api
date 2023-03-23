<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\DataTransferObject\CompleteInviteInput;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersInviteRepository;
use Throwable;

class ProcessInviteAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected CompleteInviteInput $userInvite
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Users
    {
        $invite = UsersInviteRepository::getByHash($this->userInvite->getInviteHash());

        $dto = RegisterPostDataDto::from([
            'email' => $invite->email,
            'password' => $this->userInvite->password,
            'firstname' => $this->userInvite->firstname,
            'lastname' => $this->userInvite->lastname,
            'default_company' => (string)$invite->companies_id,
            'roles_id' => $invite->role_id,
        ]);

        DB::beginTransaction();

        try {
            $user = (new RegisterUsersAction($dto))->execute();

            $company = $invite->company()->get()->first();
            $branch = $invite->branch()->get()->first();

            /*  $company->associateUser(
                 $user,
                 StateEnums::YES->getValue(),
                 $branch,
                 $invite->role_id
             ); */

            $company->associateUserApp(
                $user,
                app(Apps::class),
                StateEnums::YES->getValue(),
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
