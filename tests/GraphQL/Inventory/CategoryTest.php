<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class CategoryTest extends TestCase
{
    /**
     * testCreateCategory.
     *
     * @return void
     */
    public function testCreateCategory(): void
    {
        $data = [
            'name' => fake()->name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name
                    code,
                    is_published
                    position
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createCategory' => $data]
        ]);
    }

    /**
     * testGetCategory.
     *
     * @return void
     */
    public function testGetCategory(): void
    {
        $data = [
            'name' => fake()->name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    position,
                    is_published
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createCategory' => $data]
        ]);
        $response = $this->graphQL('
            query {
                categories {
                    data {
                        id,
                        name,
                        code,
                        position,
                        is_published
                    }
                }
            }');
        $this->assertArrayHasKey('id', $response->json()['data']['categories']['data'][0]);
    }

    /**
     * testUpdateCategory.
     *
     * @return void
     */
    public function testUpdateCategory(): void
    {
        $data = [
            'name' => fake()->name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    is_published
                    position
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createCategory' => $data]
        ]);
        $response = $this->graphQL('
            query {
                categories {
                    data {
                        id,
                        name,
                    }
                }
        }');
        $id = $response['data']['categories']['data'][0]['id'];
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($id: ID!, $data: CategoryUpdateInput!) {
                updateCategory(id: $id, input: $data)
                {
                    name
                }
            }', ['id' => $id, 'data' => $data])->assertJson([
            'data' => ['updateCategory' => $data]
        ]);
    }

    /**
     * testDeleteCategory.
     *
     * @return void
     */
    public function testDeleteCategory(): void
    {
        $data = [
            'name' => fake()->name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    is_published,
                    position
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createCategory' => $data]
        ]);
        $response = $this->graphQL('
            query {
                categories {
                    data {
                        id,
                        name,
                        is_published
                    }
                }
        }');
        $id = $response['data']['categories']['data'][0]['id'];
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($id: ID!, $data: CategoryUpdateInput!) {
                updateCategory(id: $id, input: $data)
                {
                    name
                }
            }', ['id' => $id, 'data' => $data])->assertJson([
            'data' => ['updateCategory' => $data]
        ]);
        $this->graphQL('
            mutation($id: ID!) {
                deleteCategory(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteCategory' => true]
        ]);
    }

    public function testDuplicateCategory(): void
    {
        $data = [
            'name' => fake()->name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];

        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    is_published,
                    position
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createCategory' => $data]
        ]);

        $response = $this->graphQL('
            query {
                categories {
                    data {
                        id,
                        name,
                        is_published
                    }
                }
        }');

        $id = $response['data']['categories']['data'][0]['id'];
        $name = $response['data']['categories']['data'][0]['name'];

        $this->graphQL('
        mutation($id: ID!) {
            deleteCategory(id: $id)
        }', ['id' => $id])->assertJson([
        'data' => ['deleteCategory' => true]
        ]);

        $data = [
            'name' => $name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    is_published,
                    position
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createCategory' => $data]
        ]);

    }
}
