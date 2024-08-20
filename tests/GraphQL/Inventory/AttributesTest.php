<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class AttributesTest extends TestCase
{
    /**
     * testCreate.
     *
     * @return void
     */
    public function testCreate(): void
    {
        $response = $this->createAttribute();

        $this->assertArrayHasKey('name', $response['data']['createAttribute']);
    }

    /**
     * testSearch.
     *
     * @return void
     */
    public function testSearch(): void
    {
        $response = $this->createAttribute();

        $this->assertArrayHasKey('name', $response['data']['createAttribute']);

        $response = $this->graphQL('
            query {
                attributes {
                    data {
                        name
                        values {
                            value
                        }
                    }
                }
            }');

        $this->assertArrayHasKey('name', $response->json()['data']['attributes']['data'][0]);
    }

    /**
     * testUpdate.
     *
     * @return void
     */
    public function testUpdate(): void
    {
        $response = $this->createAttribute();
        $id = $response['data']['createAttribute']['id'];

        $dataUpdate = [
            'name' => fake()->name
        ];

        $response = $this->graphQL('
            mutation($dataUpdate: AttributeUpdateInput! $id: ID!) {
                updateAttribute(input: $dataUpdate id: $id)
                {
                    name
                }
            }', ['dataUpdate' => $dataUpdate, 'id' => $id]);

        $this->assertEquals(
            $dataUpdate['name'],
            $response['data']['updateAttribute']['name']
        );
    }

    /**
     * testDelete.
     *
     * @return void
     */
    public function testDelete(): void
    {
        $response = $this->createAttribute();
        $id = $response['data']['createAttribute']['id'];

        $this->graphQL('
            mutation($id: ID!) {
                deleteAttribute(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteAttribute' => true]
        ]);
    }

    /**
     * testCreateDuplicatedSlug.
     *
     * @return void
     */
    public function testCreateDuplicatedSlug(): void
    {
        $slug = 'unique-slug-test-' . fake()->uuid;

        $response = $this->createAttribute($slug);
        $firstAttributeId = $response['data']['createAttribute']['id'];

        $response2 = $this->createAttribute($slug);

        $this->assertEquals(
            $firstAttributeId,
            $response2['data']['createAttribute']['id']
        );
    }

    /**
     * testUpdateDuplicatedSlug.
     *
     * @return void
     */
    public function testUpdateDuplicatedSlug(): void
    {
        $slug = 'unique-slug-update-test-' . fake()->uuid;
        $response = $this->createAttribute($slug);

        $slug2 = 'another-unique-slug-' . fake()->uuid;
        $response2 = $this->createAttribute($slug2);

        $response3 = $this->graphQL('
            mutation($id: ID!, $data: AttributeUpdateInput!) {
                updateAttribute(id: $id, input: $data) {
                    id
                    name
                    slug
                }
            }', [
            'id' => $response2['data']['createAttribute']['id'],
            'data' => [
                'name' => 'Updated Name',
                'slug' => $slug,
            ]
        ])->json();

        $this->assertEquals(
            $slug2,
            $response3['data']['updateAttribute']['slug']
        );
    }

    /**
     * Helper function createAttribute.
     * 
     * @param string|null $slug
     * 
     * @return array
     */
    private function createAttribute(?string $slug = null): array
    {
        $data = [
            'name' => fake()->name,
            'values' => [
                ['value' => fake()->name],
            ],
        ];

        if ($slug) {
            $data['slug'] = $slug;
        }

        return $this->graphQL('
            mutation($data: AttributeInput!) {
                createAttribute(input: $data)
                {
                    id
                    name
                    slug
                    values {
                        value
                    }
                }
            }', ['data' => $data])->json();
    }
}
