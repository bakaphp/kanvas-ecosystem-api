<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class CustomFieldsModulesTest extends TestCase
{
    public function testCreateCustomFieldModule(): void
    {
        $data = [
            'name' => fake()->word,
            'model_name' => fake()->word,
        ];
        $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldModuleInput!) {
                createCustomFieldModule(input: $input){
                    name,
                    model_name
                }
            }',
            [
            'input' => $data,
        ],
        )->assertJson([
            'data' => [
                'createCustomFieldModule' => $data,
            ],
        ]);
    }

    public function testUpdateCustomFieldModule(): void
    {
        $data = [
            'name' => fake()->word,
            'model_name' => fake()->word,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldModuleInput!) {
                createCustomFieldModule(input: $input){
                    id,
                    name,
                    model_name
                }
            }',
            [
            'input' => $data,
                ],
        );
        $response = $response->json()['data']['createCustomFieldModule'];
        $data['name'] = fake()->word;
        $data['model_name'] = fake()->word;
        $this->graphQL( /** @lang GraphQL */
            '
            mutation ($id: ID!, $input: CustomFieldModuleInput!) {
                updateCustomFieldModule(id: $id, input: $input){
                    id,
                    name,
                    model_name
                }
            }',
            [
            'id' => $response['id'],
            'input' => $data,
        ],
        )->assertJson([
            'data' => [
                'updateCustomFieldModule' => $data,
            ],
        ]);
    }

    public function testGetCustomFieldsModulesTest(): void
    {
        $data = [
            'name' => fake()->word,
            'model_name' => fake()->word,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldModuleInput!) {
                createCustomFieldModule(input: $input){
                    id,
                    name,
                    model_name
                }
            }',
            [
            'input' => $data,
        ],
        );
        $response = $response->json()['data']['createCustomFieldModule'];
        $this->graphQL( /** @lang GraphQL */
            '
            query {
                customFieldModules(
                    orderBy: [
                        { column: ID, order: DESC }
                    ]
                ) {  
                    data {
                        id,
                        name,
                        model_name
                    }
                    
            }
        }',
        )->assertJsonFragment($response);
    }

    public function testDeleteCustomFieldsModulesTest(): void
    {
        $data = [
            'name' => fake()->word,
            'model_name' => fake()->word,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldModuleInput!) {
                createCustomFieldModule(input: $input){
                    id,
                    name,
                    model_name
                }
            }',
            [
            'input' => $data,
        ],
        );
        $response = $response->json()['data']['createCustomFieldModule'];
        $this->graphQL( /** @lang GraphQL */
            '
            mutation ($id: ID!) {
                deleteCustomFieldModule(id: $id)
            }',
            [
            'id' => $response['id'],
        ],
        )->assertJson([
            'data' => [
                'deleteCustomFieldModule' => true,
            ],
        ]);
    }
}
