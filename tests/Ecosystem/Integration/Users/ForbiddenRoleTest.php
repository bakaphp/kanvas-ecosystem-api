<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Users;

use Tests\TestCase;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Bouncer;
use Kanvas\Users\Actions\RemoveCompanyAction;

final class ForbiddenRoleTest extends TestCase
{
    public function testForbiddenRole(): void
    {
        $dto = RegisterInput::from([
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'displayname' => fake()->name(),
            'email' => fake()->email(),
            'password' => fake()->password(),
            'branch' => auth()->user()->getCurrentBranch()
        ]);
        $user = (new CreateUserAction($dto))->execute();
        Bouncer::assign(RolesEnums::ADMIN->value)->to($user);
        $this->assertEquals($user->getRoles()->first(), RolesEnums::ADMIN->value);
        (new RemoveCompanyAction(
            $user,
            $user->getCurrentBranch(),
        ))->execute();
        $this->assertCount(0, $user->getRoles());
    }
}
