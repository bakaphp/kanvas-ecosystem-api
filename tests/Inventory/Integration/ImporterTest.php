<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Support\Setup;
use Tests\TestCase;

final class ImporterTest extends TestCase
{
    public function testImportAction(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $setupCompany = new Setup(
            app(Apps::class),
            auth()->user(),
            $company
        );
        $setupCompany->run();

        $attributes = [
            'attributes' => [
                [
                    'name' => fake()->word(),
                    'value' => fake()->word(),
                ],
                [
                    'name' => fake()->word(),
                    'value' => fake()->word(),
                ],
            ],
        ];

        $productData = ProductImporter::from([
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'slug' => fake()->slug(),
            'sku' => fake()->word(),
            'price' => fake()->randomNumber(2),
            'quantity' => fake()->randomNumber(2),
            'isPublished' => true,
            'files' => [
                [
                    'url' => fake()->imageUrl(),
                    'name' => fake()->word(),
                ],
                [
                    'url' => fake()->imageUrl(),
                    'name' => fake()->word(),
                ]
            ],
            'variants' => [
                [
                    'name' => fake()->word(),
                    'description' => fake()->sentence(),
                    'sku' => fake()->word(),
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    ...$attributes,
                    'files' => [
                        [
                            'url' => fake()->imageUrl(),
                            'name' => fake()->word(),
                        ],
                        [
                            'url' => fake()->imageUrl(),
                            'name' => fake()->word(),
                        ]
                    ],
                ],
                [
                    'name' => fake()->word(),
                    'description' => fake()->sentence(),
                    'sku' => fake()->word(),
                    'price' => fake()->randomNumber(2),
                    'is_published' => true,
                    'slug' => fake()->slug(),
                    ...$attributes,
                ],
            ],
            'categories' => [
                [
                    'name' => fake()->word(),
                    'code' => (string) fake()->randomNumber(3),
                    'position' => fake()->randomNumber(1),
                ]
            ],
            ...$attributes
        ]);

        $productImporter = new ProductImporterAction(
            $productData,
            $company,
            auth()->user(),
            RegionRepository::getByName('default', $company)
        );

        $this->assertTrue($productImporter->execute());
    }
}
