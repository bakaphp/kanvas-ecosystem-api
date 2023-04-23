<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Tests\TestCase;

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
}
