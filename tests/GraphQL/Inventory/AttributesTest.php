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
        $data = [
            'name' => fake()->name,
            'values' => [
                [
                    'value' => fake()->name
                ]
            ]
        ];

        $response = $this->graphQL('
            mutation($data: AttributeInput!) {
                createAttribute(input: $data)
                {
                    name
                    values {
                        value
                    }
                }
            }', ['data' => $data]);

        $this->assertArrayHasKey('name', $response->json()['data']['createAttribute']);
    }

    /**
     * testSearch.
     *
     * @return void
     */
    public function testSearch(): void
    {
        $data = [
            'name' => fake()->name,
            'values' => [
                [
                    'value' => fake()->name
                ]
            ]
        ];
        $response = $this->graphQL('
            mutation($data: AttributeInput!) {
                createAttribute(input: $data)
                {
                    name
                    values {
                        value
                    }
                }
            }', ['data' => $data]);

        $this->assertArrayHasKey('name', $response->json()['data']['createAttribute']);

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
        $data = [
            'name' => fake()->name,
            'values' => [
                [
                    'value' => fake()->name
                ]
            ]
        ];
        $response = $this->graphQL('
            mutation($data: AttributeInput!) {
                createAttribute(input: $data)
                {
                    id
                    name
                    values {
                        value
                    }
                }
            }', ['data' => $data])->json()['data']['createAttribute'];

        $this->assertArrayHasKey('name', $response);


        $id = $response['id'];
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
        $data = [
            'name' => fake()->name,
            'values' => [
                [
                    'value' => fake()->name
                ]
            ]
        ];
        $response = $this->graphQL('
            mutation($data: AttributeInput!) {
                createAttribute(input: $data)
                {
                    id
                    name
                    values {
                        value
                    }
                }
            }', ['data' => $data])->json()['data']['createAttribute'];

        $this->assertArrayHasKey('name', $response);


        $id = $response['id'];
        $this->graphQL('
            mutation($id: ID!) {
                deleteAttribute(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteAttribute' => true]
        ]);
    }
}
