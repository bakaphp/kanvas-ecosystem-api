<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class AttributesTypesTest extends TestCase
{
    /**
     * testCreate.
     *
     * @return void
     */
    public function testCreate(): void
    {
        $data = [
            'name' => fake()->name
        ];

        $response = $this->graphQL('
            mutation($data: AttributesTypeInput!) {
                createAttributeType(input: $data)
                {
                    name
                }
            }', ['data' => $data]);

        $this->assertArrayHasKey('name', $response->json()['data']['createAttributeType']);
    }

    /**
     * testSearch.
     *
     * @return void
     */
    public function testSearch(): void
    {
        $data = [
            'name' => fake()->name
        ];
        $response = $this->graphQL('
            mutation($data: AttributesTypeInput!) {
                createAttributeType(input: $data)
                {
                    name
                }
            }', ['data' => $data]);

        $this->assertArrayHasKey('name', $response->json()['data']['createAttributeType']);

        $response = $this->graphQL('
            query {
                attributesTypes {
                    data {
                        name
                    }
                }
            }');
        $this->assertArrayHasKey('name', $response->json()['data']['attributesTypes']['data'][0]);
    }

    /**
     * testUpdate.
     *
     * @return void
     */
    public function testUpdate(): void
    {
        $data = [
            'name' => fake()->name,
        ];
        $response = $this->graphQL('
            mutation($data: AttributesTypeInput!) {
                createAttributeType(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $data]);

        $this->assertArrayHasKey('name', $response->json()['data']['createAttributeType']);

        $id = $response->json()['data']['createAttributeType']['id'];
        $dataUpdate = [
            'name' => fake()->name
        ];

        $response = $this->graphQL('
            mutation($dataUpdate: AttributeTypeUpdateInput! $id: ID!) {
                updateAttributeType(input: $dataUpdate id: $id)
                {
                    name
                }
            }', ['dataUpdate' => $dataUpdate, 'id' => $id]);

        $this->assertEquals(
            $dataUpdate['name'],
            $response->json()['data']['updateAttributeType']['name']
        );
    }

    /**
     * testDelete.
     *
     * @return void
     */
    public function testDelete(): void
    {
        $data = [
            'name' => fake()->name
        ];
        $response = $this->graphQL('
            mutation($data: AttributesTypeInput!) {
                createAttributeType(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $data])->json()['data']['createAttributeType'];

        $this->assertArrayHasKey('name', $response);


        $id = $response['id'];
        $this->graphQL('
            mutation($id: ID!) {
                deleteAttributeType(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteAttributeType' => true]
        ]);
    }
}
