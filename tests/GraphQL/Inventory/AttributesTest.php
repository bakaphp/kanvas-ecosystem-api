<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Languages\Models\Languages;
use Tests\TestCase;

class AttributesTest extends TestCase
{
    /**
     * testCreate.
     */
    public function testCreate(): void
    {
        $response = $this->createAttribute();

        $this->assertArrayHasKey('name', $response['data']['createAttribute']);
    }

    /**
     * testSearch.
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
     */
    public function testUpdate(): void
    {
        $response = $this->createAttribute();
        $id = $response['data']['createAttribute']['id'];

        $dataUpdate = [
            'name' => fake()->name,
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

    public function testUpdateTranslation(): void
    {
        $response = $this->createAttribute();
        $language = Languages::first();
        $id = $response['data']['createAttribute']['id'];

        $dataUpdate = [
            'name' => fake()->name.' es',
        ];

        $response = $this->graphQL('
            mutation($dataUpdate: AttributeTranslationInput! $id: ID!, $code: String!) {
                updateAttributeTranslations(id: $id, input: $dataUpdate, code: $code)
                {
                    id
                    name
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
            $response['data']['updateAttributeTranslations']['translation']['name']
        );
    }

    /**
     * testDelete.
     */
    public function testDelete(): void
    {
        $response = $this->createAttribute();
        $id = $response['data']['createAttribute']['id'];

        $this->graphQL('
            mutation($id: ID!) {
                deleteAttribute(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteAttribute' => true],
        ]);
    }

    /**
     * testCreateDuplicatedSlug.
     */
    public function testCreateDuplicatedSlug(): void
    {
        $slug = 'unique-slug-test-'.fake()->uuid;

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
     */
    public function testUpdateDuplicatedSlug(): void
    {
        $slug = 'unique-slug-update-test-'.fake()->uuid;
        $response = $this->createAttribute($slug);

        $slug2 = 'another-unique-slug-'.fake()->uuid;
        $response2 = $this->createAttribute($slug2);

        $response3 = $this->graphQL('
            mutation($id: ID!, $data: AttributeUpdateInput!) {
                updateAttribute(id: $id, input: $data) {
                    id
                    name
                    slug
                }
            }', [
            'id'   => $response2['data']['createAttribute']['id'],
            'data' => [
                'name' => 'Updated Name',
                'slug' => $slug,
            ],
        ])->json();

        $this->assertEquals(
            $slug2,
            $response3['data']['updateAttribute']['slug']
        );
    }

    public function testCreateAttributeRequiredFields(): void
    {
        $data = [
            'name'   => fake()->name,
            'values' => [
                ['value' => fake()->name],
            ],
            'is_required' => true,
        ];

        $response = $this->graphQL('
            mutation($data: AttributeInput!) {
                createAttribute(input: $data)
                {
                    id
                    name
                    is_required
                }
            }', ['data' => $data]);
        $this->assertArrayHasKey('is_required', $response['data']['createAttribute']);
        $this->assertEquals(
            true,
            $response['data']['createAttribute']['is_required']
        );
    }

    /**
     * Helper function createAttribute.
     */
    private function createAttribute(?string $slug = null): array
    {
        $data = [
            'name'   => fake()->name,
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
