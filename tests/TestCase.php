<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Mail;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput as RegisterPostDataDto;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Symfony\Component\Mailer\Transport\TransportInterface;

class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use MakesGraphQLRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the createSymfonyTransport method
        Mail::shouldReceive('createSymfonyTransport')
            ->andReturn(\Mockery::mock(TransportInterface::class));

        // Mock the setSymfonyTransport method
        Mail::shouldReceive('setSymfonyTransport')
            ->andReturnNull();
    }
    
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
