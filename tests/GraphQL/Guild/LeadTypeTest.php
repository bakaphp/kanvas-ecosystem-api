<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadTypeTest extends TestCase
{
    public function testCreate()
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
            'is_active' => fake()->boolean
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
}
