<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadSourceTest extends TestCase
{
    public function testCreateLeadSource(): void
    {

        $companies = $this->graphQL('
                query{
                    me {
                        companies {
                            id,
                            uuid
                        }
                    }
                }
            ');
        $companiesId = json_decode($companies->decodeResponseJson()->json);
        $companiesId = $companiesId->data->me->companies[0]->uuid;
        $input = [
            'companies_id' => $companiesId,
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
        );
        $leadType = json_decode($leadType->decodeResponseJson()->json);
        $leadType = $leadType->data->createLeadType->uuid;
        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $this->graphQL(
            '
                mutation createLeadSource($input: LeadSourceInput!) {
                    createLeadSource(input: $input){
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
                    'createLeadSource' => [
                        'name' => $input['name'],
                        'description' => $input['description'],
                    ],
                ],
            ]
        );
    }

    public function testUpdateLeadSource(): void
    {
        $companies = $this->graphQL(
            '
                query{
                    me {
                        companies {
                            id,
                            uuid
                        }
                    }
                }
        '
        );
        $companiesId = json_decode($companies->decodeResponseJson()->json);
        $companiesId = $companiesId->data->me->companies[0]->uuid;
        $input = [
            'companies_id' => $companiesId,
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
        );
        $leadType = json_decode($leadType->decodeResponseJson()->json);
        $leadType = $leadType->data->createLeadType->uuid;
        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadSource = $this->graphQL(
            '
        mutation createLeadSource($input: LeadSourceInput!) {
            createLeadSource(input: $input){
                uuid
            }
        }
        ',
            [
                'input' => $input,
            ]
        );

        $leadSource = json_decode($leadSource->decodeResponseJson()->json);
        $leadSourceId = $leadSource->data->createLeadSource->uuid;

        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];

        $this->graphQL(
            '
            mutation updateLeadSource($id: ID!, $input: LeadSourceInput!) {
                updateLeadSource(id: $id, input: $input){
                    name,
                    description,
                }
            }
            ',
            [
                'input' => $input,
                'id' => $leadSourceId,
            ]
        )->assertJson(
            [
                'data' => [
                    'updateLeadSource' => [
                        'name' => $input['name'],
                        'description' => $input['description'],
                    ],
                ],
            ]
        );

    }

    public function testDeleteLeadSource(): void
    {
        $companies = $this->graphQL(
            '
                query{
                    me {
                        companies {
                            id,
                            uuid
                        }
                    }
                }
        '
        );
        $companiesId = json_decode($companies->decodeResponseJson()->json);
        $companiesId = $companiesId->data->me->companies[0]->uuid;
        $input = [
            'companies_id' => $companiesId,
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
        );
        $leadType = json_decode($leadType->decodeResponseJson()->json);
        $leadType = $leadType->data->createLeadType->uuid;
        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadSource = $this->graphQL(
            '
        mutation createLeadSource($input: LeadSourceInput!) {
            createLeadSource(input: $input){
                uuid
            }
        }
        ',
            [
                'input' => $input,
            ]
        );

        $leadSource = json_decode($leadSource->decodeResponseJson()->json);
        $leadSourceId = $leadSource->data->createLeadSource->uuid;

        $this->graphQL(
            '
            mutation deleteLeadSource($id: ID!) {
                deleteLeadSource(id: $id)
            }
            ',
            [
                'id' => $leadSourceId,
            ]
        )->assertJson(
            [
                'data' => [
                    'deleteLeadSource' => true,
                ],
            ]
        );
    }

    public function testGetLeadSource(): void
    {
        $response = $this->graphQL(
            '
                {
                    leadSources {
                        data {
                            id,
                            name,
                            description,
                            is_active,
                            leadType {
                                id
                            }
                        }
                    }
                }   
            '
        )->assertJsonStructure([
            'data' => [
                'leadSources' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'is_active',
                            'leadType' => [
                                'id',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
