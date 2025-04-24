<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Languages\Models\Languages;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ProductsTypesTest extends TestCase
{
    use InventoryCases;
    /**
     * testCreate.
     *
     */
    public function testCreate(): void
    {
        $data = [
            'name' => fake()->name,
            'weight' => 1,
        ];
        $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    name
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProductType' => $data]
        ]);
    }

    /**
     * testSearch.
     *
     */
    public function testSearch(): void
    {
        $data = [
            'name' => fake()->name,
            'weight' => 1,
        ];
        $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    name
                    weight
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProductType' => $data]
        ]);
        $response = $this->graphQL('
            query {
                productTypes {
                    data {
                        name
                        weight
                    }
                }
            }');
        $this->assertArrayHasKey('name', $response->json()['data']['productTypes']['data'][0]);
    }

    /**
     * testUpdate.
     *
     */
    public function testUpdate(): void
    {
        $response = $this->createProductType();
        $id = $response['data']['createProductType']['id'];

        $data = [
            'name' => fake()->name,
            'weight' => 2,
        ];
        $this->graphQL('
            mutation($data: ProductTypeUpdateInput! $id: ID!) {
                updateProductType(input: $data id: $id)
                {
                    name
                    weight
                }
            }', ['data' => $data, 'id' => $id])->assertJson([
            'data' => ['updateProductType' => $data]
        ]);
    }

    public function testUpdateProductTypeTranslation(): void
    {
        $response = $this->createProductType();
        $language = Languages::first();
        $id = $response['data']['createProductType']['id'];

        $dataUpdate = [
            'name' => fake()->name . ' en'
        ];

        $response = $this->graphQL('
            mutation($dataUpdate: TranslationInput!, $id: ID!, $code: String!) {
                updateProductTypeTranslations(id: $id, input: $dataUpdate, code: $code)
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
            $response['data']['updateProductTypeTranslations']['translation']['name']
        );
    }

    /**
     * testDelete.
     *
     */
    public function testDelete(): void
    {
        $response = $this->createProductType();
        $id = $response['data']['createProductType']['id'];
        $this->graphQL('
            mutation($id: ID!) {
                deleteProductType(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteProductType' => true]
        ]);
    }

    public function testCreateAttributeProductType(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $attribute = $this->createAttribute(
            company: $company,
            app: $app,
            user: $user
        );

        $data = [
            'name' => fake()->name,
            'weight' => 1,
            'products_attributes' => [
                [
                    'id' => $attribute->getId(),
                    'is_required' => true
                ]
            ]
        ];

        $productType = $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    id
                    name
                    weight
                    products_attributes{
                        id
                        name
                        is_required
                    }
                }
            }', ['data' => $data])->json();

            $this->assertArrayHasKey('id', $productType['data']['createProductType']);

            $this->assertEquals(
                $data['products_attributes'][0]['id'],
                $productType['data']['createProductType']['products_attributes'][0]['id']
            );
    }

    private function createProductType()
    {
        $data = [
            'name' => fake()->name,
            'weight' => 1,
        ];

        return $this->graphQL('
            mutation($data: ProductTypeInput!) {
                createProductType(input: $data)
                {
                    id
                    name
                    weight
                }
            }', ['data' => $data])->json();
    }
}
