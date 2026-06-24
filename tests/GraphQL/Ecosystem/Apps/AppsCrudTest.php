<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;

class AppsCrudTest extends TestCase
{
    public function testCreate()
    {
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $input = [
            'name' => fake()->name,
            'url' => fake()->url,
            'description' => trim(substr(fake()->text, 0, 44)),
            'domain' => fake()->safeEmailDomain,
            'is_actived' => true,
            'ecosystem_auth' => false,
            'payments_active' => false,
            'is_public' => true,
            'domain_based' => false,
        ];
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $input: AppInput!
            ){
                createApp(
                    input: $input
                ) {
                    id
                    name
                    url
                    description
                    domain
                    is_actived
                    ecosystem_auth
                    payments_active
                    is_public
                    domain_based
                }
            }',
            [
                'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );
        $response->assertJson([
            'data' => [
                'createApp' => $input,
            ],
        ]);
    }

    public function testGet()
    {
        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                apps(first: 10) {
                    data {

                        id,
                        name,
                        key,
                        default_apps_plan_id,
                        created_at
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }
            }
            '
        );
        $this->assertArrayHasKey('data', $response);
    }

    public function testListedOncePerAppWithPaginatorInfo()
    {
        $user = auth()->user();
        $app = Apps::orderBy('id', 'desc')->first();

        foreach ([0, 999001, 999002, 999003] as $companyId) {
            UsersAssociatedApps::firstOrCreate([
                'users_id' => $user->getKey(),
                'companies_id' => $companyId,
                'apps_id' => $app->getKey(),
            ], [
                'identify_id' => $user->getKey(),
                'user_active' => StateEnums::ON->getValue(),
                'user_role' => $user->roles_id,
                'password' => $user->password,
            ]);
        }

        $response = $this->graphQL(
            /** @lang GraphQL */
            '
            query {
                apps(first: 50) {
                    data {
                        id
                    }
                    paginatorInfo {
                        total
                        currentPage
                        lastPage
                    }
                }
            }
            '
        );

        $ids = array_column($response['data']['apps']['data'], 'id');

        // The user is linked to the app through several users_associated_apps rows
        // (one per company). Requesting paginatorInfo used to drop or duplicate such
        // apps because the JOIN + groupBy made the data and count queries disagree.
        $this->assertSame(
            1,
            count(array_keys($ids, (string) $app->getKey())),
            'App associated through multiple companies must appear exactly once'
        );
        $this->assertSame($ids, array_values(array_unique($ids)), 'No app may be listed more than once');
    }

    /**
     * test_updated.
     *
     * @return void
     */
    public function testUpdate()
    {
        $apps = Apps::orderBy('id', 'desc')->first();
        $user = auth()->user();
        $apps->associateUser($user, StateEnums::ON->getValue());
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $input = [
            'name' => fake()->name,
            'url' => fake()->url,
            'description' => trim(substr(fake()->text, 0, 44)),
            'domain' => fake()->safeEmailDomain,
            'is_actived' => true,
            'ecosystem_auth' => false,
            'payments_active' => false,
            'is_public' => true,
            'domain_based' => false,
        ];

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $input: AppInput!
            ){
                updateApp(
                    id: "' . $apps->key . '",
                    input: $input
                ) {
                    name
                    url
                    description
                    domain
                    is_actived
                    ecosystem_auth
                    payments_active
                    is_public
                    domain_based
                }
            }',
            [
                'input' => $input,
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $response->assertJson([
            'data' => [
                'updateApp' => $input,
            ],
        ]);
    }
}
