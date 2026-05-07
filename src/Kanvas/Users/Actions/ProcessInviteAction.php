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
        protected CompleteInviteInput $userInvite,
        protected ?Users $existingUser = null
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
            $user = $this->existingUser ?? (new CreateUserAction($dto))->execute();

            $company = $invite->company;
            #$branch = $invite->branch;
            $app = app(Apps::class);

            if ($this->existingUser) {
                $company->associateUser(
                    $user,
                    StateEnums::YES->getValue(),
                    $invite->branch,
                    (int) ($invite->role_id ?? null)
                );

                if (is_object($invite->branch)) {
                    $company->associateUser(
                        $user,
                        StateEnums::YES->getValue(),
                        $invite->branch,
                        (int) ($invite->role_id ?? null)
                    );
                }
            }

            $company->associateUserApp(
                $user,
                $app,
                StateEnums::YES->getValue(),
            );

            if (! $this->existingUser) {
                new SendUserNotificationAction($app, $company, $user)->execute('admin-new-user', $company->toArray());
            }

            $invite->softDelete();
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $user;
    }
}
