<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrganizationTypeTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testSearchOrganizationTypes(): void
    {
        $name = 'OrgType-' . fake()->unique()->uuid();
        $this->graphQL(
            '
            mutation createOrganizationType($input: OrganizationTypeInput!) {
                createOrganizationType(input: $input){
                    uuid
                }
            }
            ',
            [
                'input' => [
                    'name' => $name,
                    'description' => fake()->text,
                    'is_active' => true,
                ],
            ]
        );

        $response = $this->graphQL(
            '
            query organizationTypes($search: String) {
                organizationTypes(search: $search) {
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
                    'organizationTypes' => [
                        'data' => [
                            '*' => ['name'],
                        ],
                    ],
                ],
            ]
        )->decodeResponseJson()->json;

        $names = array_column(json_decode($response, true)['data']['organizationTypes']['data'], 'name');
        $this->assertContains($name, $names);
    }

    public function testOrganizationTypesCanBeOrderedByIsDefault(): void
    {
        $this->createOrganizationType('OrgTypeDefault-' . fake()->unique()->uuid(), true);
        $this->createOrganizationType('OrgTypeRegular-' . fake()->unique()->uuid(), false);

        $query = '
            query organizationTypes($orderBy: [QueryOrganizationTypesOrderByOrderByClause!]) {
                organizationTypes(orderBy: $orderBy) {
                    data {
                        name
                        is_default
                    }
                }
            }
        ';

        $descending = $this->graphQL($query, [
            'orderBy' => [['column' => 'IS_DEFAULT', 'order' => 'DESC']],
        ])->assertOk()->json('data.organizationTypes.data');

        $ascending = $this->graphQL($query, [
            'orderBy' => [['column' => 'IS_DEFAULT', 'order' => 'ASC']],
        ])->assertOk()->json('data.organizationTypes.data');

        $this->assertTrue($descending[0]['is_default']);
        $this->assertFalse($ascending[0]['is_default']);
    }

    protected function createOrganizationType(string $name, bool $isDefault): void
    {
        $this->graphQL(
            '
            mutation createOrganizationType($input: OrganizationTypeInput!) {
                createOrganizationType(input: $input) {
                    uuid
                }
            }
            ',
            [
                'input' => [
                    'name' => $name,
                    'description' => fake()->text,
                    'is_active' => true,
                    'is_default' => $isDefault,
                ],
            ]
        )->assertOk();
    }
}
