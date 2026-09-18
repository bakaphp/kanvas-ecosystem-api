<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\ApplyFilesystemMapperAction;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

class FilesystemMapperTest extends TestCase
{
    /**
     * test_save.
     */
    public function testCreateFilesystemMapper(): void
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(Products::class, $app);
        $filesystemMapperInput = [
            'name' => 'test',
            'file_header' => ['header1', 'header2'],
            'system_module_id' => $systemModule->getId(),
            'mapping' => [
                'name' => 'List Number',
                'productName' => 'List Number',
                'description' => 'Features',
                'sku' => 'List Number',
                'slug' => 'List Number',
                'regionId' => 'regionId',
                'price' => 'Original List Price',
                'discountPrice' => 'Discounted Price',
                'quantity' => 'Quantity',
                'isPublished' => 'Is Published',
                'files' => 'File URL',
                'productType' => 'Product Type',
                'warehouse' => 382,
                'categories' => 'Style',
                'customFields' => [],
                'attributes' => [
                    [
                        'name' => '_Property Type',
                        'value' => 'Property Type',
                    ],
                    [
                        'name' => '_Card Format',
                        'value' => 'Card Format',
                    ],
                    // Add more attributes here as needed
                ],
            ],
        ];

        $response = $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $input: FilesystemMapperInput!
                ){
                    createFilesystemMapper(input: $input) {
                        id,
                        name,
                    }
                }
            ',
            [
                'input' => $filesystemMapperInput,
            ],
        );

        $response->assertJson([
            'data' => [
                'createFilesystemMapper' => [
                    'name' => $filesystemMapperInput['name'],
                ],
            ],
        ]);

        $response->assertJsonStructure([
            'data' => [
                'createFilesystemMapper' => [
                    'id',
                    'name',
                ],
            ],
        ]);
    }
    public function testCreateFilesystemMapperDefault(): void
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(Products::class, $app);
        $filesystemMapperInput = [
            'name' => 'test',
            'file_header' => ['header1', 'header2'],
            'system_module_id' => $systemModule->getId(),
            'is_default' => true,
            'mapping' => [
                'name' => 'List Number',
                'productName' => 'List Number',
                'description' => 'Features',
                'sku' => 'List Number',
                'slug' => 'List Number',
                'regionId' => 'regionId',
                'price' => 'Original List Price',
                'discountPrice' => 'Discounted Price',
                'quantity' => 'Quantity',
                'isPublished' => 'Is Published',
                'files' => 'File URL',
                'productType' => 'Product Type',
                'warehouse' => 382,
                'categories' => 'Style',
                'customFields' => [],
                'attributes' => [
                    [
                        'name' => '_Property Type',
                        'value' => 'Property Type',
                    ],
                    [
                        'name' => '_Card Format',
                        'value' => 'Card Format',
                    ],
                    // Add more attributes here as needed
                ],
            ],
        ];

        $response = $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $input: FilesystemMapperInput!
                ){
                    createFilesystemMapper(input: $input) {
                        id,
                        name,
                        is_default
                    }
                }
            ',
            [
                'input' => $filesystemMapperInput,
            ],
        );

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'createFilesystemMapper' => [
                    'id',
                    'name',
                    'is_default'
                ],
            ],
        ]);
        $this->assertEquals('test', $response->json('data.createFilesystemMapper.name'));
    }
    public function testUpdateFilesystemMapper(): void
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(Products::class, $app);
        $filesystemMapperInput = [
            'name' => 'test',
            'file_header' => ['header1', 'header2'],
            'system_module_id' => $systemModule->getId(),
            'mapping' => [
                'name' => 'List Number',
                'productName' => 'List Number',
                'description' => 'Features',
                'sku' => 'List Number',
                'slug' => 'List Number',
                'regionId' => 'regionId',
                'price' => 'Original List Price',
                'discountPrice' => 'Discounted Price',
                'quantity' => 'Quantity',
                'isPublished' => 'Is Published',
                'files' => 'File URL',
                'productType' => 'Product Type',
                'warehouse' => 382,
                'categories' => 'Style',
                'customFields' => [],
                'attributes' => [
                    [
                        'name' => '_Property Type',
                        'value' => 'Property Type',
                    ],
                    [
                        'name' => '_Card Format',
                        'value' => 'Card Format',
                    ],
                    // Add more attributes here as needed
                ],
            ],
        ];

        $response = $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $input: FilesystemMapperInput!
                ){
                    createFilesystemMapper(input: $input) {
                        id,
                        name,
                    }
                }
            ',
            [
                'input' => $filesystemMapperInput,
            ],
        );

        $response->assertJson([
            'data' => [
                'createFilesystemMapper' => [
                    'name' => $filesystemMapperInput['name'],
                ],
            ],
        ]);

        $response->assertJsonStructure([
            'data' => [
                'createFilesystemMapper' => [
                    'id',
                    'name',
                ],
            ],
        ]);

        $id = $response->json('data.createFilesystemMapper.id');
        unset($filesystemMapperInput['system_module_id']);
        $response = $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $input: UpdateFilesystemImportInput!
                ){
                    updateFilesystemMapper(input: $input) {
                        id,
                        name,
                    }
                }
            ',
            [
                'input' => array_merge($filesystemMapperInput, ['mapper_id' => $id]),
            ],
        );
        $response->assertJson([
            'data' => [
                'updateFilesystemMapper' => [
                    'name' => $filesystemMapperInput['name'],
                ],
            ],
        ]);
    }

    public function testDeleteFilesystemMapper(): void
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(Products::class, $app);
        $filesystemMapperInput = [
            'name' => 'test',
            'file_header' => ['header1', 'header2'],
            'system_module_id' => $systemModule->getId(),
            'mapping' => [
                'name' => 'List Number',
                'productName' => 'List Number',
                'description' => 'Features',
                'sku' => 'List Number',
                'slug' => 'List Number',
                'regionId' => 'regionId',
                'price' => 'Original List Price',
                'discountPrice' => 'Discounted Price',
                'quantity' => 'Quantity',
                'isPublished' => 'Is Published',
                'files' => 'File URL',
                'productType' => 'Product Type',
                'warehouse' => 382,
                'categories' => 'Style',
                'customFields' => [],
                'attributes' => [
                    [
                        'name' => '_Property Type',
                        'value' => 'Property Type',
                    ],
                    [
                        'name' => '_Card Format',
                        'value' => 'Card Format',
                    ],
                    // Add more attributes here as needed
                ],
            ],
        ];

        $response = $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $input: FilesystemMapperInput!
                ){
                    createFilesystemMapper(input: $input) {
                        id,
                        name,
                    }
                }
            ',
            [
                'input' => $filesystemMapperInput,
            ],
        );

        $response->assertJson([
            'data' => [
                'createFilesystemMapper' => [
                    'name' => $filesystemMapperInput['name'],
                ],
            ],
        ]);

        $response->assertJsonStructure([
            'data' => [
                'createFilesystemMapper' => [
                    'id',
                    'name',
                ],
            ],
        ]);

        $id = $response->json('data.createFilesystemMapper.id');

        $this->graphQL(/** @lang GraphQL */ '
                mutation(
                    $id: ID!
                ){
                    deleteFilesystemMapper(id: $id)
                }
            ',
            [
                'input' => ['id' => $id],
            ],
        );
    }

    /**
     * Follows the full mapper "recipe" flow entirely through the GraphQL layer — create People
     * mapper, create Product mapper, link them via update, read the link back via the list query —
     * then feeds the mutation-created mappers into `ApplyFilesystemMapperAction` to prove the
     * config produced by these exact mutations actually creates a linked Product + People.
     */
    public function testCreatesLinkedMappersThroughMutationsAndReadsThemBackThroughTheQuery(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $productType = new CreateProductTypeAction(
            new ProductsTypesDto(
                $company,
                $user,
                'Mapper Link Flow Type ' . uniqid(),
                'test',
                1,
                true,
            ),
            $user,
        )->execute();

        $peopleModule = SystemModulesRepository::getByModelName(People::class, $app);
        $productModule = SystemModulesRepository::getByModelName(Products::class, $app);

        $peopleMapperInput = [
            'name' => 'Mapper Link Flow People ' . uniqid(),
            'system_module_id' => $peopleModule->getId(),
            'has_header' => false,
            'mapping' => [
                'firstname' => 'Contact_Name__c',
                'email' => 'Contact_Email__c',
                'phone' => 'Contact_Phone__c',
            ],
        ];

        $peopleResponse = $this->graphQL(/** @lang GraphQL */ '
                mutation($input: FilesystemMapperInput!) {
                    createFilesystemMapper(input: $input) {
                        id
                        name
                    }
                }
            ',
            ['input' => $peopleMapperInput],
        );
        $peopleResponse->assertSuccessful();
        $peopleMapperId = (int) $peopleResponse->json('data.createFilesystemMapper.id');
        $this->assertNotSame(0, $peopleMapperId);

        $productMapperInput = [
            'name' => 'Mapper Link Flow Product ' . uniqid(),
            'system_module_id' => $productModule->getId(),
            'has_header' => false,
            'mapping' => [
                'name' => 'Property_Name__c',
                'description' => 'Brand__c',
                'sku' => 'Id',
                'attributes' => [
                    ['Deal Status' => 'Deal_Status__c'],
                    ['Marketing Status' => 'Marketing_Status__c'],
                    ['Building Type' => 'Location_Type__c'],
                    ['Building Size' => 'Gross_SF__c'],
                    ['Acreage' => 'Property_Acreage__c'],
                    ['Year Built' => 'Year_Built__c'],
                    ['Zoning' => 'Zoning__c'],
                    ['Offering' => 'Ask_Deal_Type__c'],
                    ['Street' => 'Street__c'],
                    ['City' => 'City__c'],
                    ['State Province' => 'State_Province__c'],
                    ['Zip Code' => 'Zip_Code__c'],
                    ['Latitude' => 'Latitude__c'],
                    ['Longitude' => 'Longitude__c'],
                ],
            ],
            'configuration' => [
                'product_type_id' => $productType->getId(),
            ],
        ];

        $productResponse = $this->graphQL(/** @lang GraphQL */ '
                mutation($input: FilesystemMapperInput!) {
                    createFilesystemMapper(input: $input) {
                        id
                        name
                    }
                }
            ',
            ['input' => $productMapperInput],
        );
        $productResponse->assertSuccessful();
        $productMapperId = (int) $productResponse->json('data.createFilesystemMapper.id');
        $this->assertNotSame(0, $productMapperId);

        $updateInput = [
            'mapper_id' => $productMapperId,
            'name' => $productMapperInput['name'],
            'mapping' => $productMapperInput['mapping'],
            'configuration' => [
                'product_type_id' => $productType->getId(),
                'links' => [
                    [
                        'mapper_id' => $peopleMapperId,
                        'source_object' => 'Location_Contact__c',
                        'match_field' => 'Location__c',
                        'link_field' => 'broker_people_id',
                    ],
                ],
            ],
        ];

        $updateResponse = $this->graphQL(/** @lang GraphQL */ '
                mutation($input: UpdateFilesystemImportInput!) {
                    updateFilesystemMapper(input: $input) {
                        id
                        configuration
                    }
                }
            ',
            ['input' => $updateInput],
        );
        $updateResponse->assertSuccessful();
        $this->assertSame(
            $peopleMapperId,
            $updateResponse->json('data.updateFilesystemMapper.configuration.links.0.mapper_id'),
        );

        $queryResponse = $this->graphQL(/** @lang GraphQL */ '
                query($where: QueryFilesystemMappersWhereWhereConditions) {
                    filesystemMappers(where: $where) {
                        data {
                            id
                            configuration
                        }
                    }
                }
            ',
            [
                'where' => [
                    'column' => 'ID',
                    'operator' => 'EQ',
                    'value' => $productMapperId,
                ],
            ],
        );
        $queryResponse->assertSuccessful();
        $this->assertSame(
            $peopleMapperId,
            $queryResponse->json('data.filesystemMappers.data.0.configuration.links.0.mapper_id'),
        );

        $productMapper = FilesystemMapper::find($productMapperId);

        $product = new ApplyFilesystemMapperAction(
            $app,
            $company,
            $user,
            $productMapper,
            'SFFLOW001',
            [
                'Id' => 'SFFLOW001',
                'Property_Name__c' => 'Mapper Flow Test Property',
                'Brand__c' => 'Test Brand',
                'Deal_Status__c' => 'Negotiations Ongoing',
                'Marketing_Status__c' => 'Active',
                'Location_Type__c' => 'Retail',
                'Gross_SF__c' => '12500',
                'Property_Acreage__c' => '1.75',
                'Year_Built__c' => '1998',
                'Zoning__c' => 'Commercial',
                'Ask_Deal_Type__c' => 'Sale',
                'Street__c' => '123 Main St',
                'City__c' => 'East Tawas',
                'State_Province__c' => 'MI',
                'Zip_Code__c' => '48730',
                'Latitude__c' => '44.2769',
                'Longitude__c' => '-83.4327',
            ],
            [
                $peopleMapperId => [
                    'Id' => 'SFFLOWCONTACT001',
                    'Contact_Name__c' => 'Mapper Flow Test Broker',
                    'Contact_Email__c' => 'broker@mapperflow.test',
                    'Contact_Phone__c' => '555-0100',
                ],
            ],
        )->execute();

        $this->assertSame('Mapper Flow Test Property', $product->name);
        $this->assertSame('Negotiations Ongoing', $product->getAttributeByName('Deal Status')?->value);
        $this->assertSame('Retail', $product->getAttributeByName('Building Type')?->value);
        $this->assertEquals('48730', $product->getAttributeByName('Zip Code')?->value);
        $brokerId = $product->get('broker_people_id');
        $this->assertNotNull($brokerId);
        $this->assertSame('Mapper Flow Test Broker', People::find((int) $brokerId)->firstname);
    }
}
