<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\StateEnums;
use Tests\TestCase;

class AppsCrudTest extends TestCase
{
    public function testCreate()
    {
        $input = [
            'name' => fake()->name,
            'url' => fake()->url,
            'description' => trim(substr(fake()->text, 0, 44)),
            'domain' => fake()->safeEmailDomain,
            'is_actived' => 1,
            'ecosystem_auth' => 0,
            'payments_active' => 0,
            'is_public' => 1,
            'domain_based' => 0
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
                'input' => $input
            ]
        );
        $response->assertJson([
            'data' => [
                'createApp' => $input
            ]
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

        $input = [
            'name' => fake()->name,
            'url' => fake()->url,
            'description' => trim(substr(fake()->text, 0, 44)),
            'domain' => fake()->safeEmailDomain,
            'is_actived' => 1,
            'ecosystem_auth' => 0,
            'payments_active' => 0,
            'is_public' => 1,
            'domain_based' => 0
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
                'input' => $input
            ]
        );

        $response->assertJson([
            'data' => [
                'updateApp' => $input
            ]
        ]);
    }
}
