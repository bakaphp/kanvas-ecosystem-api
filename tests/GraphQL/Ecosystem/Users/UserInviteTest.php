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
                    'custom_fields' => [],
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
                    'custom_fields' => [],

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
        )->assertSuccessful()
        ->assertSeeText('email')
        ->assertSeeText('id');

        $this->assertEquals($response->json('data.processInvite.email'), $invite['email']);
    }

    public function testDeleteInvite(): void
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
                    'custom_fields' => [],
                ],
            ]
        );

        $invite = $response->json('data.inviteUser');

        $this->graphQL( /** @lang GraphQL */
            '
            mutation deleteInvite($id: Int!) {
                deleteInvite(id: $id)
            }',
            [
                'id' => $invite['id'],
            ]
        )->assertSuccessful();
    }

    public function testGetInvite(): void
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
                    'custom_fields' => [],
                ],
            ]
        );

        $invite = $response->json('data.inviteUser');

        $response = $this->graphQL( /** @lang GraphQL */
            '
                mutation getInvite($hash: String!) {
                    getInvite(hash: $hash)
                    {
                        email,
                        invite_hash,
                    }
                }',
            [

                    'hash' => $invite['invite_hash'],
            ]
        )
        ->assertSuccessful()
        ->assertSeeText('email')
        ->assertSeeText('invite_hash');

        $this->assertEquals($response->json('data.getInvite.email'), $invite['email']);
    }

    public function testListInvites(): void
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
                    'custom_fields' => [],
                ],
            ]
        );

        $this->graphQL( /** @lang GraphQL */
            '
                query usersInvites {
                    usersInvites(first: 10) {
                        data {
                            email,
                            id,
                            invite_hash
                        }
                    }
                }',
            [

            ]
        )
        ->assertSuccessful()
        ->assertSeeText('email')
        ->assertSeeText('invite_hash');
    }
}
