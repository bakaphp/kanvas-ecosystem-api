<?php
declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Kanvas\Apps\Apps\Models\Apps;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesApplication;

class AppsCrudTest extends BaseTestCase
{
    use CreatesApplication;
    use MakesGraphQLRequests;

    public function test_save()
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

    public function test_get()
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
    public function test_updated()
    {
        $apps = Apps::orderBy('id', 'desc')->first();
        $input = [
            'name' => fake()->name,
            'url' => fake()->url,
            'description' => substr(fake()->text, 0, 44),
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
                    id: ' . $apps->id . ',
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
                'updateApp' => $input
            ]
        ]);
    }
}
