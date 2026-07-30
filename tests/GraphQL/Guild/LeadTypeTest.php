<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadTypeTest extends TestCase
{
    public function testCreate()
    {
        $input = [
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $this->graphQL(
            '
            mutation createLeadType($input: LeadTypeInput!) {
                createLeadType(input: $input){
                    name,
                    description,
                }
            }
            ',
            [
                'input' => $input,
            ]
        )->assertJson(
            [
                'data' => [
                    'createLeadType' => [
                        'name' => $input['name'],
                        'description' => $input['description'],
                    ],
                ],
            ]
        );
    }

    public function testUpdateLeadType()
    {
        $input = [
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadType = $this->graphQL(
            '
            mutation createLeadType($input: LeadTypeInput!) {
                createLeadType(input: $input){
                   uuid
                }
            }
            ',
            [
                'input' => $input,
            ]
        )->decodeResponseJson()->json;
        $leadType = json_decode($leadType);
        $leadType = $leadType->data->createLeadType;
        $input = [
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $this->graphQL(
            '
            mutation updateLeadType($id: ID!, $input: LeadTypeInput!) {
                updateLeadType(id: $id, input: $input){
                    name,
                    description,
                }
            }
            ',
            [
                'id' => $leadType->uuid,
                'input' => $input,
            ]
        )->assertJson(
            [
                'data' => [
                    'updateLeadType' => [
                        'name' => $input['name'],
                        'description' => $input['description'],
                    ],
                ],
            ]
        );
    }

    public function testDeleteLeadType()
    {
        $input = [
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadType = $this->graphQL(
            '
            mutation createLeadType($input: LeadTypeInput!) {
                createLeadType(input: $input){
                   uuid
                }
            }
            ',
            [
                'input' => $input,
            ]
        )->decodeResponseJson()->json;
        $leadType = json_decode($leadType);
        $leadType = $leadType->data->createLeadType;
        $this->graphQL(
            '
            mutation deleteLeadType($id: ID!) {
                deleteLeadType(id: $id)
            }
            ',
            [
                'id' => $leadType->uuid,
            ]
        )->assertJson(
            [
                'data' => [
                    'deleteLeadType' => true,
                ],
            ]
        );
    }

    public function testSearchLeadTypes()
    {
        $name = 'LeadType-' . fake()->unique()->uuid();
        $this->graphQL(
            '
            mutation createLeadType($input: LeadTypeInput!) {
                createLeadType(input: $input){
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
            query leadTypes($search: String) {
                leadTypes(search: $search) {
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
                    'leadTypes' => [
                        'data' => [
                            '*' => ['name'],
                        ],
                    ],
                ],
            ]
        )->decodeResponseJson()->json;

        $names = array_column(json_decode($response, true)['data']['leadTypes']['data'], 'name');
        $this->assertContains($name, $names);
    }

    public function testLeadTypes()
    {
        $input = [
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $this->graphQL(
            '
            mutation createLeadType($input: LeadTypeInput!) {
                createLeadType(input: $input){
                   uuid
                }
            }
            ',
            [
                'input' => $input,
            ]
        );
        $this->graphQL(
            '
            query{
                leadTypes {
                    data {
                        name,
                        description
                    }
                }
            }
            '
        )->assertJsonStructure(
            [
                'data' => [
                    'leadTypes' => [
                        'data' => [
                            '*' => [
                                'name',
                                'description',
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
