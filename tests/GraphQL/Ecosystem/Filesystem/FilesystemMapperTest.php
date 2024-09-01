<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
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
}
