<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Tests\TestCase;

/**
 * Tag coverage for the `importProduct` GraphQL payload shape.
 *
 * The CSV path hands the importer plain tag strings; GraphQL hands it
 * `[TagInput!]` objects (`[['name' => 'x']]`). Both reach the same action, so
 * the object shape needs its own coverage — the CSV end-to-end test cannot
 * exercise it.
 *
 * The transport itself is not driven here: the `importProduct` resolver spools
 * the payload to S3 through `Storage::build()`, which `Storage::fake()` cannot
 * intercept and which throws without real AWS credentials. These tests start
 * one layer in, at the DTO the resolver builds.
 */
final class ProductImporterTagsTest extends TestCase
{
    use ProductImportFixtures;

    public function testImporterAttachesGraphQlShapedTagsToProductAndVariants(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = $this->makeRegion($company, $app, $user);

        $slug = 'gql-tags-' . uniqid();
        $skuOne = 'GQL-TAG-1-' . uniqid();
        $skuTwo = 'GQL-TAG-2-' . uniqid();

        new ProductImporterAction(
            ProductImporter::from([
                'name' => 'GraphQL Tagged Product',
                'slug' => $slug,
                'sku' => $skuOne,
                'tags' => [['name' => 'summer'], ['name' => 'sale']],
                'variants' => [
                    [
                        'name' => 'Variant One',
                        'sku' => $skuOne,
                        'tags' => [['name' => 'red'], ['name' => 'cotton']],
                    ],
                    [
                        'name' => 'Variant Two',
                        'sku' => $skuTwo,
                        'tags' => [['name' => 'blue']],
                    ],
                ],
            ]),
            $company,
            $user,
            $region,
            $app,
        )->execute();

        $product = $this->findImportedProduct($slug, $app, $company);
        $this->assertNotNull($product);

        $this->assertEqualsCanonicalizing(
            ['summer', 'sale'],
            $product->tags()->pluck('name')->all(),
            'TagInput objects on the product must be flattened to names and attached',
        );

        $tagsBySku = [];
        foreach ($product->variants()->get() as $variant) {
            $tagsBySku[$variant->sku] = $variant->tags()->pluck('name')->all();
        }

        $this->assertEqualsCanonicalizing(['red', 'cotton'], $tagsBySku[$skuOne] ?? []);
        $this->assertEqualsCanonicalizing(['blue'], $tagsBySku[$skuTwo] ?? []);
    }

    public function testImporterAcceptsPlainStringTagsOnProductAndVariants(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = $this->makeRegion($company, $app, $user);

        $slug = 'str-tags-' . uniqid();
        $sku = 'STR-TAG-' . uniqid();

        new ProductImporterAction(
            ProductImporter::from([
                'name' => 'String Tagged Product',
                'slug' => $slug,
                'sku' => $sku,
                'tags' => ['summer', 'sale'],
                'variants' => [
                    [
                        'name' => 'Only Variant',
                        'sku' => $sku,
                        'tags' => ['red'],
                    ],
                ],
            ]),
            $company,
            $user,
            $region,
            $app,
        )->execute();

        $product = $this->findImportedProduct($slug, $app, $company);

        $this->assertEqualsCanonicalizing(['summer', 'sale'], $product->tags()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['red'],
            $product->variants()->first()->tags()->pluck('name')->all(),
        );
    }

    public function testReimportWithoutTagsKeysLeavesExistingTagsIntact(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = $this->makeRegion($company, $app, $user);

        $slug = 'keep-tags-' . uniqid();
        $sku = 'KEEP-TAG-' . uniqid();

        $payload = [
            'name' => 'Kept Tags Product',
            'slug' => $slug,
            'sku' => $sku,
            'tags' => [['name' => 'summer']],
            'variants' => [
                [
                    'name' => 'Only Variant',
                    'sku' => $sku,
                    'tags' => [['name' => 'red']],
                ],
            ],
        ];

        new ProductImporterAction(ProductImporter::from($payload), $company, $user, $region, $app)->execute();

        // A caller that simply omits `tags` (the common partial-update case)
        // must not clear what a previous import attached — syncTags detaches
        // everything before adding, so the omission has to be guarded.
        unset($payload['tags'], $payload['variants'][0]['tags']);
        new ProductImporterAction(ProductImporter::from($payload), $company, $user, $region, $app)->execute();

        $product = $this->findImportedProduct($slug, $app, $company);

        $this->assertEqualsCanonicalizing(['summer'], $product->tags()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['red'],
            $product->variants()->first()->tags()->pluck('name')->all(),
        );
    }

    public function testReimportWithNewTagsReplacesThePreviousSetOnBothLevels(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = $this->makeRegion($company, $app, $user);

        $slug = 'replace-tags-' . uniqid();
        $sku = 'REPLACE-TAG-' . uniqid();

        $build = fn (array $productTags, array $variantTags) => [
            'name' => 'Replaced Tags Product',
            'slug' => $slug,
            'sku' => $sku,
            'tags' => $productTags,
            'variants' => [
                [
                    'name' => 'Only Variant',
                    'sku' => $sku,
                    'tags' => $variantTags,
                ],
            ],
        ];

        new ProductImporterAction(
            ProductImporter::from($build([['name' => 'summer'], ['name' => 'sale']], [['name' => 'red']])),
            $company,
            $user,
            $region,
            $app,
        )->execute();

        new ProductImporterAction(
            ProductImporter::from($build([['name' => 'winter']], [['name' => 'blue']])),
            $company,
            $user,
            $region,
            $app,
        )->execute();

        $product = $this->findImportedProduct($slug, $app, $company);

        $this->assertEqualsCanonicalizing(['winter'], $product->tags()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['blue'],
            $product->variants()->first()->tags()->pluck('name')->all(),
        );
    }
}
