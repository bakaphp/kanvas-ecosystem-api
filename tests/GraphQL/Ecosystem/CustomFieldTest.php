<?php
declare(strict_types= 1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    public function testCreateCustomField(): void
    {
        $data = [
            'name' => fake()->word,
            'model_name' => fake()->word,
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
        
        
    }
}
