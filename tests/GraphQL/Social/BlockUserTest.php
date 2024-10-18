<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class BlockUserTest extends TestCase
{
    public function testBlockedUsers()
    {
        $response = $this->graphQL(
            '
                query BlockedUsers {
                    blockedUsers {
                        data {
                            id
                            displayname
                        }
                    }
                }
            '
        );

        $response->assertJsonStructure([
            'data' => [
                'blockedUsers' => [
                    'data' => [
                        '*' => [
                            'id',
                            'displayname',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testBlockUser()
    {
        $user = Users::factory()->create();
        $branch = auth()->user()->getCurrentBranch();
        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();
        $this->graphQL(
            '
            mutation BlockUser($id: ID!) {
                blockUser(id: $id)
            }
        ',
            [
                'id' => $user->id,
            ]
        )->assertJson([
            'data' => [
                'blockUser' => true,
            ],
        ]);
    }

    public function testUnBlockUser()
    {
        $user = Users::factory()->create();
        $branch = auth()->user()->getCurrentBranch();
        (new RegisterUsersAppAction($user, app(Apps::class)))->execute($user->password);
        //add user to current company
        (new AssignCompanyAction(
            $user,
            $branch,
            RolesRepository::getByNameFromCompany(RolesEnums::ADMIN->value),
            app(Apps::class)
        ))->execute();

        $this->graphQL(
            '
            mutation UnBlockUser($id: ID!) {
                unBlockUser(id: $id)
            }
        ',
            [
                'id' => $user->id,
            ]
        )->assertJson([
            'data' => [
                'unBlockUser' => false,
            ],
        ]);
    }
}
