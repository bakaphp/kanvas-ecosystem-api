<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;
use Kanvas\CustomFields\Models\CustomFieldsTypes;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class CustomFieldsTest extends TestCase
{
    public function testSetCustomField(): void
    {
        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => fake()->word,
                'data' => [
                    'hellos' => fake()->numberBetween(1, 100),
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->assertJson([
            'data' => [
                'setCustomField' => true,
            ],
        ]);
    }

    /**
     * @deprecated
     */
    public function testGetCustomField(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                getCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => null,
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->assertSee($value);
    }

    public function testGetCustomFieldQuery(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            query customField( 
            $name : String!, 
            $system_module_uuid: String! , 
            $entity_id : String!) {
                customField(
                    name: $name, 
                    system_module_uuid: $system_module_uuid,
                    entity_id : $entity_id
                )
            }',
            [
            'name' => $key,
            'system_module_uuid' => get_class(auth()->user()),
            'entity_id' => auth()->user()->uuid,
        ],
        )->assertSee($value);
    }

    /**
     * @deprecated
     */
    public function testGetAllCustomField(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => fake()->word,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                getAllCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => null,
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->assertSee($value);
    }

    public function testGetAllCustomFieldQuery(): void
    {
        $key = fake()->word;
        $value = fake()->numberBetween(1, 100);

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => fake()->word,
                'data' => [
                    'hellos' => $value,
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => auth()->user()->uuid,
            ],
        ],
        )->json();

        $this->graphQL( /** @lang GraphQL */
            '
            query customFields( 
            $name : String!, 
            $system_module_uuid: String! , 
            $entity_id : String!) {
                customFields(
                    name: $name, 
                    system_module_uuid: $system_module_uuid,
                    entity_id : $entity_id
                )
            }',
            [
            'name' => $key,
            'system_module_uuid' => get_class(auth()->user()),
            'entity_id' => auth()->user()->uuid,
        ],
        )->assertSee($value);
    }

    public function testDeleteCustomField(): void
    {
        $key = fake()->word;
        $userUuid = auth()->user()->uuid;

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                setCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => [
                    'hellos' => fake()->numberBetween(1, 100),
                ],
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => $userUuid,
            ],
        ],
        );

        $results = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldInput!) {
                deleteCustomField(input: $input)
            }',
            [
            'input' => [
                'name' => $key,
                'data' => null,
                'system_module_uuid' => get_class(auth()->user()),
                'entity_id' => $userUuid,
            ],
        ],
        )->assertJson([
            'data' => [
                'deleteCustomField' => true,
            ],
        ]);
    }

    public function testCreateCustomFields(): void
    {
        $products = Products::factory()->create();
        $systemModule = SystemModulesRepository::getByModelName(Products::class);
        $data = [
            'name' => fake()->word,
            'model_name' => fake()->word,
            'system_modules_id' => $systemModule->id,
        ];
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation ($input: CustomFieldModuleInput!) {
                createCustomFieldModule(input: $input){
                    name,
                    model_name,
                }
            }',
            [
                'input' =>$data
            ],
        )->assertJson([
            'data' => [
                'createCustomFieldModule' => [
                    'name' => $data['name'],
                    'model_name' => $data['model_name'],
                ],
            ],
        ]);
        $customFieldModules = $response->json('data.createCustomFieldModule.id');
        $data = [
            'name' => fake()->word,
            'data' => [
                'hellos' => fake()->numberBetween(1, 100),
            ],
            'system_module_uuid' => $systemModule->uuid,
            'entity_id' => $products->getId(),
            'field_type_id' => CustomFieldsTypes::findFirst()->getId(),
        ];
    }
}
