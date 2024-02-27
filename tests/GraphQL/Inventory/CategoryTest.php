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
        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name
                    code,
                    position
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

        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    position
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
                        position
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
        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    position
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
            mutation($id: Int!, $data: CategoryUpdateInput!) {
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

        ];
        $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name,
                    code,
                    position
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
            mutation($id: Int!, $data: CategoryUpdateInput!) {
                updateCategory(id: $id, input: $data)
                {
                    name
                }
            }', ['id' => $id, 'data' => $data])->assertJson([
            'data' => ['updateCategory' => $data]
        ]);
        $this->graphQL('
            mutation($id: Int!) {
                deleteCategory(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteCategory' => true]
        ]);
    }
}
