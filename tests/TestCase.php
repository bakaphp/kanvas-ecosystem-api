<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use MakesGraphQLRequests;

    /**
     * createUser.
     *
     * @return Users
     */
    public function createUser(): Users
    {
        $dto = RegisterPostDataDto::from([
            'email' => fake()->email,
            'password' => fake()->password(8),
            'firstname' => fake()->firstName,
            'lastname' => fake()->lastName,
        ]);
        $user = (new RegisterUsersAction($dto))->execute();
        return $user;
    }
}
