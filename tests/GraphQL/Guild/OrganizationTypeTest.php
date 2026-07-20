<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class OrganizationTypeTest extends TestCase
{
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
}
