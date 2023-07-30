<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Tests\TestCase;

class UserInviteTest extends TestCase
{
    public function testInviteNewUser(): void
    {
        Notification::fake();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteUser($data: InviteInput!) {
                inviteUser(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
                    'role_id' => RolesRepository::getByNameFromCompany('Users')->id,
                    'email' => fake()->email(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                ],
            ]
        )
        ->assertSuccessful()
        ->assertSeeText('invite_hash');
    }

    public function testProcessInvite(): void
    {
        Notification::fake();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteUser($data: InviteInput!) {
                inviteUser(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
                    'role_id' => RolesRepository::getByNameFromCompany('Users')->id,
                    'email' => fake()->email(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                ],
            ]
        );

        $invite = $response->json('data.inviteUser');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation processInvite($data: CompleteInviteInput!) {
                processInvite(input: $data)
                {
                   id,
                   email
                }
            }',
            [
                'data' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'password' => '123456789',
                    'invite_hash' => $invite['invite_hash'],
                ],
            ]
        );

        print_r($response->json());
        die();
    }
}
