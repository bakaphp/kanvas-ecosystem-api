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

        $dto = RegisterPostDataDto::fromArray([
            'email' => $invite->email,
            'password' => $this->userInvite->password,
            'firstname' => $this->userInvite->firstname,
            'lastname' => $this->userInvite->lastname ?? '',
            'phone_number' => $this->userInvite->phone_number ?? null,
            'role_ids' => [$invite->role_id],
        ], $invite->branch);

        DB::beginTransaction();

        try {
            $user = (new CreateUserAction($dto))->execute();

            $company = $invite->company()->get()->first();
            $branch = $invite->branch()->get()->first();

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
