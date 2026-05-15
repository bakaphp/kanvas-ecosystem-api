<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Filesystem\Actions\CreateFilesystemMapperAction;
use Kanvas\Filesystem\Actions\ImportDataFromFilesystemAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemMapper;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ImportDataFromFilesystemActionTest extends TestCase
{
    private function buildBaseMapper(): array
    {
        return [
            'name' => 'Product Name',
            'description' => 'Description',
            'sku' => 'SKU',
            'slug' => 'SKU',
            'regionId' => 'regionId',
            'price' => 'Price',
            'productType' => [
                'name' => '_Default',
                'description' => '_Default',
                'is_published' => true,
                'weight' => 1,
            ],
            'customFields' => [],
            'attributes' => [],
            'categories' => [
                [
                    'name' => '_Test Category',
                    'code' => '_test-category',
                    'is_published' => true,
                    'position' => 1,
                ],
            ],
            'variants' => [
                [
                    'name' => 'Product Name',
                    'sku' => 'SKU',
                    'price' => 'Price',
                    'is_published' => true,
                    'slug' => 'SKU',
                ],
            ],
        ];
    }

    private function createRegionAndWarehouse(): array
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);

        $region = (new CreateRegionAction(
            new Region(
                $user->getCurrentCompany(),
                $app,
                $user,
                Currencies::getById(1),
                'Test Region ' . uniqid(),
                'test-region-' . uniqid(),
                null,
                1,
            ),
            $user
        ))->execute();

        $warehouse = (new CreateWarehouseAction(
            new Warehouses(
                $user->getCurrentCompany(),
                $app,
                $user,
                $region,
                'Test Warehouse ' . uniqid(),
                'Test Location',
                true,
                true,
            ),
            $user
        ))->execute();

        return [$region, $warehouse];
    }

    public function testMapperParsesTagsAsArray(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $mapper = array_merge($this->buildBaseMapper(), ['tags' => 'Tags']);

        $filesystemMapper = (new CreateFilesystemMapperAction(
            new FilesystemMapper(
                $app,
                $user->getCurrentBranch(),
                $user,
                SystemModulesRepository::getByModelName(Products::class),
                'Tags Mapper ' . uniqid(),
                [],
                $mapper,
            )
        ))->execute();

        $values = [
            'Product Name' => fake()->word(),
            'SKU' => fake()->numerify('SKU-####'),
            'Description' => fake()->sentence(),
            'Price' => '99.99',
            'regionId' => 1,
            'Categories' => 'Electronics',
            'Tags' => 'sale,new arrival, featured',
        ];

        $action = new ImportDataFromFilesystemAction(new FilesystemImports());
        $result = $action->mapper($filesystemMapper->mapping, $values);

        $this->assertIsArray($result['tags']);
        $this->assertCount(3, $result['tags']);
        $this->assertContains('sale', $result['tags']);
        $this->assertContains('new arrival', $result['tags']);
        $this->assertContains('featured', $result['tags']);
    }

    public function testMapperTrimsTagWhitespace(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $mapper = array_merge($this->buildBaseMapper(), ['tags' => 'Tags']);

        $filesystemMapper = (new CreateFilesystemMapperAction(
            new FilesystemMapper(
                $app,
                $user->getCurrentBranch(),
                $user,
                SystemModulesRepository::getByModelName(Products::class),
                'Tags Trim Mapper ' . uniqid(),
                [],
                $mapper,
            )
        ))->execute();

        $action = new ImportDataFromFilesystemAction(new FilesystemImports());
        $result = $action->mapper($filesystemMapper->mapping, [
            'Product Name' => fake()->word(),
            'SKU' => fake()->numerify('SKU-####'),
            'Description' => fake()->sentence(),
            'Price' => '50.00',
            'regionId' => 1,
            'Categories' => 'Tools',
            'Tags' => '  red ,  blue  ,  green  ',
        ]);

        $this->assertEquals(['red', 'blue', 'green'], $result['tags']);
    }

    public function testMapperEmptyTagsReturnsNoTagKey(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $mapper = array_merge($this->buildBaseMapper(), ['tags' => 'Tags']);

        $filesystemMapper = (new CreateFilesystemMapperAction(
            new FilesystemMapper(
                $app,
                $user->getCurrentBranch(),
                $user,
                SystemModulesRepository::getByModelName(Products::class),
                'Tags Empty Mapper ' . uniqid(),
                [],
                $mapper,
            )
        ))->execute();

        $action = new ImportDataFromFilesystemAction(new FilesystemImports());
        $result = $action->mapper($filesystemMapper->mapping, [
            'Product Name' => fake()->word(),
            'SKU' => fake()->numerify('SKU-####'),
            'Description' => fake()->sentence(),
            'Price' => '50.00',
            'regionId' => 1,
            'Categories' => 'Tools',
            'Tags' => '',
        ]);

        $this->assertEmpty($result['tags']);
    }

    public function testImportProductWithTagsCreatesAndAssociatesTags(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);

        [$region] = $this->createRegionAndWarehouse();

        $mapper = array_merge($this->buildBaseMapper(), ['tags' => 'Tags']);

        $filesystemMapper = (new CreateFilesystemMapperAction(
            new FilesystemMapper(
                $app,
                $user->getCurrentBranch(),
                $user,
                SystemModulesRepository::getByModelName(Products::class),
                'Full Import Tags Mapper ' . uniqid(),
                [],
                $mapper,
            )
        ))->execute();

        $tagOne = 'tag-' . uniqid();
        $tagTwo = 'tag-' . uniqid();

        $values = [
            'Product Name' => fake()->word(),
            'SKU' => fake()->numerify('SKU-####'),
            'Description' => fake()->sentence(),
            'Price' => '120.00',
            'regionId' => $region->getId(),
            'Categories' => fake()->word(),
            'Tags' => $tagOne . ',' . $tagTwo,
        ];

        $action = new ImportDataFromFilesystemAction(new FilesystemImports());
        $mapped = $action->mapper($filesystemMapper->mapping, $values);

        $product = (new ProductImporterAction(
            ProductImporter::from($mapped),
            $user->getCurrentCompany(),
            $user,
            $region
        ))->execute();

        $this->assertInstanceOf(Products::class, $product);

        $productTags = $product->tags()->pluck('name')->toArray();
        $this->assertContains($tagOne, $productTags);
        $this->assertContains($tagTwo, $productTags);
    }

    public function testImportProductWithExistingTagsReusesExistingTags(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);

        [$region] = $this->createRegionAndWarehouse();

        $mapper = array_merge($this->buildBaseMapper(), ['tags' => 'Tags']);

        $filesystemMapper = (new CreateFilesystemMapperAction(
            new FilesystemMapper(
                $app,
                $user->getCurrentBranch(),
                $user,
                SystemModulesRepository::getByModelName(Products::class),
                'Reuse Tags Mapper ' . uniqid(),
                [],
                $mapper,
            )
        ))->execute();

        $sharedTag = 'shared-' . uniqid();
        $action = new ImportDataFromFilesystemAction(new FilesystemImports());

        $skuA = fake()->numerify('SKU-####');
        $mappedA = $action->mapper($filesystemMapper->mapping, [
            'Product Name' => fake()->word(),
            'SKU' => $skuA,
            'Description' => fake()->sentence(),
            'Price' => '10.00',
            'regionId' => $region->getId(),
            'Categories' => fake()->word(),
            'Tags' => $sharedTag,
        ]);

        $productA = (new ProductImporterAction(
            ProductImporter::from($mappedA),
            $user->getCurrentCompany(),
            $user,
            $region
        ))->execute();

        $skuB = fake()->numerify('SKU-####');
        $mappedB = $action->mapper($filesystemMapper->mapping, [
            'Product Name' => fake()->word(),
            'SKU' => $skuB,
            'Description' => fake()->sentence(),
            'Price' => '20.00',
            'regionId' => $region->getId(),
            'Categories' => fake()->word(),
            'Tags' => $sharedTag,
        ]);

        $productB = (new ProductImporterAction(
            ProductImporter::from($mappedB),
            $user->getCurrentCompany(),
            $user,
            $region
        ))->execute();

        $tagIdA = $productA->tags()->where('name', $sharedTag)->value('tags.id');
        $tagIdB = $productB->tags()->where('name', $sharedTag)->value('tags.id');

        $this->assertNotNull($tagIdA);
        $this->assertEquals($tagIdA, $tagIdB);
    }
}
