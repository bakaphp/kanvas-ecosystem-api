<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class VariantAttributeTest extends TestCase
{
    /**
     * testAddAttributeToVariant.
     *
     * @return void
     */
    public function testAddAttributeToVariant(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $response = $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                id
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
        ]);
        $productId = $response->json()['data']['createProduct']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $productId
        ];
        $response = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            { 
                id
                name
                description
                products_id
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createVariant' => $data]
        ]);
        $variantId = $response->json()['data']['createVariant']['id'];

        $dataAtribute = [
            'name' => fake()->name,
            'value' => fake()->name
        ];
        $response = $this->graphQL('
        mutation($data: AttributeInput!) {
            createAttribute(input: $data)
            {
                id
                name
                value
            }
        }', ['data' => $dataAtribute]);
        $attributeId = $response->json()['data']['createAttribute']['id'];
        $response = $this->graphQL('
            mutation($id: Int! $attributes_id: Int! $input: VariantsAttributesInput!) {
                addAttributeToVariant(id: $id, attributes_id: $attributes_id, input: $input)
                {
                    id
                    name
                }
            }
        ', [
            'id' => $variantId,
            'attributes_id' => $attributeId,
            'input' => [
                'value' => fake()->name,
                'name' => fake()->name,
            ]
        ]);
        $this->assertArrayHasKey('data', $response->json());
    }

    /**
     * testRemoveAttributeFromVariant.
     *
     * @return void
     */
    public function testRemoveAttributeFromVariant(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $response = $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                id
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
        ]);
        $productId = $response->json()['data']['createProduct']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $productId
        ];
        $response = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            { 
                id
                name
                description
                products_id
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createVariant' => $data]
        ]);
        $variantId = $response->json()['data']['createVariant']['id'];

        $dataAtribute = [
            'name' => fake()->name,
            'value' => fake()->name
        ];
        $response = $this->graphQL('
        mutation($data: AttributeInput!) {
            createAttribute(input: $data)
            {
                id
                name
            }
        }', ['data' => $dataAtribute]);
        $attributeId = $response->json()['data']['createAttribute']['id'];
        $response = $this->graphQL('
            mutation($id: Int! $attributes_id: Int! $input: VariantsAttributesInput!) {
                addAttributeToVariant(id: $id, attributes_id: $attributes_id, input: $input)
                {
                    id
                    name
                }
            }
        ', [
            'id' => $variantId,
            'attributes_id' => $attributeId,
            'input' => [
                'value' => fake()->name,
                'name' => fake()->name,
            ]
        ]);
        $this->assertArrayHasKey('data', $response->json());
        $response = $this->graphQL('
        mutation($id: Int! $attributesId: Int!) {
            removeAttributeToVariant(id:$id attributes_id:$attributesId)
            {
                id
                name
            }
        }', [
            'id' => $variantId,
            'attributesId' => $attributeId,
        ]);
        $this->assertArrayHasKey('data', $response->json());
    }
}
