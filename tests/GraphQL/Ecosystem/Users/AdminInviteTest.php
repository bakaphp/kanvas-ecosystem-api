<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use Illuminate\Support\Facades\Notification;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class AdminInviteTest extends TestCase
{
    public function testInviteNewAdmin(): void
    {
        Notification::fake();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteAdmin($data: AdminInviteInput!) {
                inviteAdmin(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
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

    public function testProcessAdminInvite(): void
    {
        Notification::fake();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteAdmin($data: AdminInviteInput!) {
                inviteAdmin(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
                    'email' => fake()->email(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'custom_fields' => [],

                ],
            ]
        );

        $invite = $response->json('data.inviteAdmin');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation processAdminInvite($data: CompleteInviteInput!) {
                processAdminInvite(input: $data)
                {
                   id
                }
            }',
            [
                'data' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'password' => fake()->password(8),
                    'invite_hash' => $invite['invite_hash'],
                    'phone_number' => fake()->phoneNumber(),

                ],
            ]
        )->assertSuccessful()
        ->assertSeeText('id');

        $this->assertArrayHasKey('id', $response->json('data.processAdminInvite'));
        $userId = $response->json('data.processAdminInvite.id');
        $user = Users::getById($userId);
        $this->assertEquals($user->email, $invite['email']);
    }

    public function testDeleteInvite(): void
    {
        Notification::fake();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteAdmin($data: AdminInviteInput!) {
                inviteAdmin(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
                    'email' => fake()->email(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'custom_fields' => [],
                ],
            ]
        );

        $invite = $response->json('data.inviteAdmin');

        $this->graphQL( /** @lang GraphQL */
            '
            mutation deleteAdminInvite($id: Int!) {
                deleteAdminInvite(id: $id)
            }',
            [
                'id' => $invite['id'],
            ]
        )->assertSuccessful();
    }

    public function testListInvites(): void
    {
        Notification::fake();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteAdmin($data: AdminInviteInput!) {
                inviteAdmin(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
                    'email' => fake()->email(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'custom_fields' => [],
                ],
            ]
        );

        $this->graphQL( /** @lang GraphQL */
            '
                query adminInvites {
                    adminInvites(first: 10) {
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

    public function testGetAdminInvite(): void
    {
        Notification::fake();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation inviteAdmin($data: AdminInviteInput!) {
                inviteAdmin(input: $data)
                {
                   id,
                   email,
                   invite_hash,
                }
            }',
            [
                'data' => [
                    'email' => fake()->email(),
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                ],
            ]
        );

        $invite = $response->json('data.inviteAdmin');

        $response = $this->graphQL( /** @lang GraphQL */
            '
                query getAdminInvite($hash: String!) {
                    getAdminInvite(hash: $hash)
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

        $this->assertEquals($response->json('data.getAdminInvite.email'), $invite['email']);
    }

}
