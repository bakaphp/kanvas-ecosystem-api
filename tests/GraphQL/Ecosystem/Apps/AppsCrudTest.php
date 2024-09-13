<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Apps;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
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
