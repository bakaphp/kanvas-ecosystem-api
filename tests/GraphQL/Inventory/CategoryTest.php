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
     * testUpdateCategory.
     *
     */
    public function testUpdateCategoryTranslation(): void
    {
        $response = $this->createCategory();
        $language = Languages::first();
        $id = $response['data']['createCategory']['id'];

        $dataUpdate = [
            'name' => fake()->name . ' en'
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
                'id' => $id,
                'code' => $language->code
            ]);

        $this->assertEquals(
            $dataUpdate['name'],
            $response['data']['updateCategoryTranslations']['translation']['name']
        );
    }

    /**
     * testDeleteCategory.
     *
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
        $response = $this->createCategory();
        $id = $response['data']['createCategory']['id'];
        $name = $response['data']['createCategory']['name'];

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

    public function testCategoryParentChildRelationship(): void
    {
        // Create parent category
        $parentData = [
            'name' => fake()->name,
            'code' => fake()->unique()->word,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];

        $parentResponse = $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name
                    code
                    is_published
                    position
                    weight
                }
            }', ['data' => $parentData])->assertJson([
            'data' => ['createCategory' => $parentData]
        ]);

        $parentId = $parentResponse->json()['data']['createCategory']['id'];

        // Create child category with parent_id
        $childData = [
            'name' => fake()->name,
            'code' => fake()->unique()->word,
            'position' => 1,
            'is_published' => true,
            'weight' => 0,
            'parent_id' => (int) $parentId
        ];

        $childResponse = $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name
                    code
                    is_published
                    position
                    weight
                    parent {
                        id
                        name
                    }
                }
            }', ['data' => $childData]);

        $childResponse->assertJsonPath('data.createCategory.parent.id', $parentId);
        $childId = $childResponse->json()['data']['createCategory']['id'];

        // Query parent category and verify children relationship
        $response = $this->graphQL('
            query($id: Mixed!) {
                categories(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        name
                        children(first: 10) {
                            data {
                                id
                                name
                            }
                        }
                    }
                }
            }', ['id' => $parentId]);

        $children = $response->json()['data']['categories']['data'][0]['children']['data'];
        $this->assertNotEmpty($children);
        $childIds = array_column($children, 'id');
        $this->assertContains($childId, $childIds);
    }

    public function testCategoryMultipleChildrenRelationship(): void
    {
        // Create parent category
        $parentData = [
            'name' => fake()->name,
            'code' => fake()->unique()->word,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
        ];

        $parentResponse = $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $parentData]);

        $parentId = $parentResponse->json()['data']['createCategory']['id'];

        // Create multiple child categories
        $childIds = [];
        for ($i = 0; $i < 3; $i++) {
            $childData = [
                'name' => fake()->name,
                'code' => fake()->unique()->word,
                'position' => $i + 1,
                'is_published' => true,
                'weight' => 0,
                'parent_id' => (int) $parentId
            ];

            $childResponse = $this->graphQL('
                mutation($data: CategoryInput!) {
                    createCategory(input: $data)
                    {
                        id
                        name
                        parent {
                            id
                        }
                    }
                }', ['data' => $childData]);

            $childResponse->assertJsonPath('data.createCategory.parent.id', $parentId);
            $childIds[] = $childResponse->json()['data']['createCategory']['id'];
        }

        // Query parent and verify all children
        $response = $this->graphQL('
            query($id: Mixed!) {
                categories(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        name
                        children(first: 10) {
                            data {
                                id
                                name
                            }
                        }
                    }
                }
            }', ['id' => $parentId]);

        $children = $response->json()['data']['categories']['data'][0]['children']['data'];
        $this->assertCount(3, $children);

        $returnedChildIds = array_column($children, 'id');
        foreach ($childIds as $childId) {
            $this->assertContains($childId, $returnedChildIds);
        }
    }

    public function testUpdateCategoryParent(): void
    {
        // Create two parent categories
        $parent1Response = $this->createCategory();
        $parent1Id = $parent1Response['data']['createCategory']['id'];

        $parent2Response = $this->createCategory();
        $parent2Id = $parent2Response['data']['createCategory']['id'];

        // Create child category under parent1
        $childData = [
            'name' => fake()->name,
            'code' => fake()->unique()->word,
            'position' => 1,
            'is_published' => true,
            'weight' => 0,
            'parent_id' => (int) $parent1Id
        ];

        $childResponse = $this->graphQL('
            mutation($data: CategoryInput!) {
                createCategory(input: $data)
                {
                    id
                    name
                    parent {
                        id
                    }
                }
            }', ['data' => $childData]);

        $childId = $childResponse->json()['data']['createCategory']['id'];
        $childResponse->assertJsonPath('data.createCategory.parent.id', $parent1Id);

        // Update child to have parent2 as parent
        $updateData = [
            'name' => fake()->name,
            'parent_id' => (int) $parent2Id
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $data: CategoryUpdateInput!) {
                updateCategory(id: $id, input: $data)
                {
                    id
                    name
                    parent {
                        id
                    }
                }
            }', ['id' => $childId, 'data' => $updateData]);

        $updateResponse->assertJsonPath('data.updateCategory.parent.id', $parent2Id);
    }

    private function createCategory()
    {
        $data = [
            'name' => fake()->name,
            'code' => fake()->name,
            'position' => 1,
            'is_published' => true,
            'weight' => 0
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
