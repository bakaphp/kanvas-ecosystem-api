<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Inventory\Status\Models\Status;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class StatusTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $scope = RolesEnums::getScope($this->apps, global: true);
        Bouncer::scope()->to($scope);
        Bouncer::assign('Admins')->to($this->user);
        Bouncer::allow('Admins')->to(['create', 'view'], Status::class);
    }

    public function testSearchStatus(): void
    {
        $name = 'Status-' . fake()->unique()->uuid();
        $this->graphQL(
            '
            mutation createStatus($input: StatusInput!) {
                createStatus(input: $input){
                    id
                    name
                }
            }
            ',
            [
                'input' => [
                    'name' => $name,
                    'is_published' => true,
                ],
            ]
        )->assertSuccessful();

        $response = $this->graphQL(
            '
            query status($search: String) {
                status(search: $search) {
                    data {
                        name
                    }
                }
            }
            ',
            [
                'search' => $name,
            ]
        )->assertJsonStructure(
            [
                'data' => [
                    'status' => [
                        'data' => [
                            '*' => ['name'],
                        ],
                    ],
                ],
            ]
        )->decodeResponseJson()->json;

        $names = array_column(json_decode($response, true)['data']['status']['data'], 'name');
        $this->assertContains($name, $names);
    }
}
