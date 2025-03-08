<?php
declare(strict_types= 1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;
use Kanvas\Guild\Leads\Models\Lead;
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
            'system_modules_id' => $moduleId,
            'custom_field_module_id' => $customFieldTypeId,
        ];

        $customField = $this->graphQL('
            mutation ($input: CustomFieldInput!) {
                createCustomField(input: $input){
                    id,
                    name,
                    label
                }
            }',
            [
                'input' => $data,
            ],
        )->assertJson([
                'data' => [
                    'createCustomField' => [
                        'name' => $data['name'],
                        'label' => $data['label'],
                    ],
                ],
        ]);
        
    }
}
