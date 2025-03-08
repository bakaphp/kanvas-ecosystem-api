<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    public function testCreateCustomField(): void
    {
        $data = [
            'name' => fake()->word,
            'model_name' => Lead::class,
        ];
        $moduleId = $this->graphQL( /** @lang GraphQL */
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
        )->json('data.createCustomFieldModule.id');

        $customFieldTypeId = $this->graphQL( /** @lang GraphQL */
            '
            query {
                customFieldTypes {
                    data {
                        id,
                        name,
                        description
                    }
                }
            }'
        )->json('data.customFieldTypes.data.0.id');

        $data = [
            'name' => fake()->name,
            'label' => fake()->name,
            'custom_field_module_id' => $moduleId,
            'field_type_id' => $customFieldTypeId,
        ];

        $customField = $this->graphQL(
            '
            mutation ($input: CustomFieldsInput!) {
                createCustomFields(input: $input){
                    name,
                    label
                }
            }',
            [
                'input' => $data,
            ],
        )->assertJson([
                'data' => [
                    'createCustomFields' => [
                        'name' => $data['name'],
                        'label' => $data['label'],
                    ],
                ],
        ]);
    }

    public function testUpdateCustomField(): void
    {
        $data = [
                   'name' => fake()->word,
                   'model_name' => Lead::class,
               ];
        $moduleId = $this->graphQL( /** @lang GraphQL */
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
        )->json('data.createCustomFieldModule.id');

        $customFieldTypeId = $this->graphQL( /** @lang GraphQL */
            '
            query {
                customFieldTypes {
                    data {
                        id,
                        name,
                        description
                    }
                }
            }'
        )->json('data.customFieldTypes.data.0.id');

        $data = [
            'name' => fake()->name,
            'label' => fake()->name,
            'custom_field_module_id' => $moduleId,
            'field_type_id' => $customFieldTypeId,
        ];

        $customFieldId = $this->graphQL(
            '
            mutation ($input: CustomFieldsInput!) {
                createCustomFields(input: $input){
                   id
                }
            }',
            [
                'input' => $data,
            ],
        )->json('data.createCustomFields.id');
        $data['name'] = fake()->name;
        
        $this->graphQL(
            /** @lang GraphQL */
            '
            mutation updateCustomFields($id: ID!, $input: CustomFieldsInput!){
                updateCustomFields(id: $id, input: $input){
                    name,
                    label
                }
            }',
            [
                'id' => $customFieldId,
                'input' => $data,
            ]
        )->assertJson([
            'data'=> [
                'updateCustomFields' => [
                    'name' => $data['name'],
                    'label' => $data['label'],
                ],
            ]
        ]);
    }
}
