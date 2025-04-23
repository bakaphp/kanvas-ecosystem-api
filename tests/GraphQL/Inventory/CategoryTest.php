<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Languages\Models\Languages;
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
            'name'         => fake()->name,
            'code'         => fake()->name,
            'position'     => 1,
            'is_published' => true,
            'weight'       => 0,
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
            'data' => ['createCategory' => $data],
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
            'name'         => fake()->name,
            'code'         => fake()->name,
            'position'     => 1,
            'is_published' => true,
            'weight'       => 0,
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
            'data' => ['createCategory' => $data],
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
            'name'         => fake()->name,
            'code'         => fake()->name,
            'position'     => 1,
            'is_published' => true,
            'weight'       => 0,
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
            'data' => ['createCategory' => $data],
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
            'data' => ['updateCategory' => $data],
        ]);
    }

    /**
     * testUpdateCategory.
     *
     * @return void
     */
    public function testUpdateCategoryTranslation(): void
    {
        $response = $this->createCategory();
        $language = Languages::first();
        $id = $response['data']['createCategory']['id'];

        $dataUpdate = [
            'name' => fake()->name.' en',
        ];

        $response = $this->graphQL('
            mutation($dataUpdate: TranslationInput!, $id: ID!, $code: String!) {
                updateCategoryTranslations(id: $id, input: $dataUpdate, code: $code)
                {
                    id
                    name,
                    translation(languageCode: $code){
                        name
                        language{
                            code
                            language
                        }
                    }
                }
            }', [
            'dataUpdate' => $dataUpdate,
            'id'         => $id,
            'code'       => $language->code,
        ]);

        $this->assertEquals(
            $dataUpdate['name'],
            $response['data']['updateCategoryTranslations']['translation']['name']
        );
    }

    /**
     * testDeleteCategory.
     *
     * @return void
     */
    public function testDeleteCategory(): void
    {
        $response = $this->createCategory();
        $id = $response['data']['createCategory']['id'];
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
            'data' => ['updateCategory' => $data],
        ]);
        $this->graphQL('
            mutation($id: ID!) {
                deleteCategory(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteCategory' => true],
        ]);
    }

    public function testDuplicateCategory(): void
    {
        $response = $this->createCategory();
        $id = $response['data']['createCategory']['id'];
        $name = $response['data']['createCategory']['name'];

        $this->graphQL('
        mutation($id: ID!) {
            deleteCategory(id: $id)
        }', ['id' => $id])->assertJson([
            'data' => ['deleteCategory' => true],
        ]);

        $data = [
            'name'         => $name,
            'code'         => fake()->name,
            'position'     => 1,
            'is_published' => true,
            'weight'       => 0,
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
            'data' => ['createCategory' => $data],
        ]);
    }

    private function createCategory()
    {
        $data = [
            'name'         => fake()->name,
            'code'         => fake()->name,
            'position'     => 1,
            'is_published' => true,
            'weight'       => 0,
        ];

        return $this->graphQL('
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
            }', ['data' => $data])->json();
    }
}
