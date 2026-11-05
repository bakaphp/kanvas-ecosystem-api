<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\TestCase;
use Throwable;

class ProductFileImportTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    private const string MUTATION = 'mutation($input: ProductFileImportInput!) {
        importProductFromFile(input: $input) { id uuid status results exception }
    }';

    public function testCreatesImportHistoryWithoutCreatingMapper(): void
    {
        Queue::fake();
        $input = $this->input();
        $mapperCount = FilesystemMapper::count();
        $response = $this->graphQL(self::MUTATION, ['input' => $input]);
        $response->assertJsonMissingPath('errors');
        $response->assertJsonPath('data.importProductFromFile.status', 'pending');
        $id = $response->json('data.importProductFromFile.id');
        $this->assertNotNull($id, $response->getContent());
        $import = FilesystemImports::findOrFail($id);
        $this->assertNull($import->filesystem_mapper_id);
        $this->assertEquals($input['mapping'], $import->extra['inline_product_import']['mapping']);
        $this->assertSame($mapperCount, FilesystemMapper::count());
        $this->graphQL('{ filesystemImportHistory { data { id status filesystem_mapper { id } } } }')
            ->assertJsonFragment(['id' => (string) $id, 'status' => 'pending', 'filesystem_mapper' => null]);
    }

    public function testRejectsFileFromAnotherCompany(): void
    {
        Queue::fake();
        $input = $this->input();
        Filesystem::where('id', $input['filesystem_id'])->update(['companies_id' => 0]);
        $this->graphQL(self::MUTATION, ['input' => $input])->assertJsonStructure(['errors']);
    }

    public function testRejectsFileFromAnotherApp(): void
    {
        Queue::fake();
        $input = $this->input();
        Filesystem::where('id', $input['filesystem_id'])->update(['apps_id' => 0]);
        $this->graphQL(self::MUTATION, ['input' => $input])->assertJsonStructure(['errors']);
    }

    public function testRejectsProductTypeFromAnotherCompany(): void
    {
        Queue::fake();
        $input = $this->input();
        ProductsTypes::where('id', $input['configuration']['product_type_id'])->update(['companies_id' => 99999999]);
        $this->graphQL(self::MUTATION, ['input' => $input])->assertJsonStructure(['errors']);
    }

    public function testRejectsMissingRequiredMapping(): void
    {
        $input = $this->input();
        unset($input['mapping']['sku']);
        $this->graphQL(self::MUTATION, ['input' => $input])->assertJsonStructure(['errors']);
    }

    public function testRejectsMissingProductTypeAndInjectedConfiguration(): void
    {
        $input = $this->input();
        $input['configuration'] = ['system_module_id' => 1];
        $this->graphQL(self::MUTATION, ['input' => $input])->assertJsonStructure(['errors']);
    }

    public function testRejectsInvalidConfiguredChannelAndWarehouse(): void
    {
        Queue::fake();
        $input = $this->input();
        foreach (['channels_id', 'warehouse_id'] as $field) {
            $invalid = $input;
            $invalid['configuration'][$field] = 2147483647;
            $this->graphQL(self::MUTATION, ['input' => $invalid])->assertJsonStructure(['errors']);
        }
    }

    public function testTransformationRejectsUnscopedMappedResources(): void
    {
        $input = $this->input();
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $csv = tempnam(sys_get_temp_dir(), 'inline-csv-');
        $jsonl = tempnam(sys_get_temp_dir(), 'inline-jsonl-');
        file_put_contents($csv, "Slug,Name,Plate\nvehicle,Vehicle,L520115\n");

        try {
            foreach (['warehouses', 'channels'] as $resource) {
                $mapping = $input['mapping'];
                $mapping[$resource] = [[$resource === 'warehouses' ? 'id' : 'channels_id' => 2147483647]];
                $import = new FilesystemImports();
                $import->setRelation('company', $company);
                $import->setRelation('app', $app);
                $import->extra = ['inline_product_import' => ['mapping' => $mapping, 'configuration' => $input['configuration']]];
                $rejected = false;

                try {
                    new ImportProductFromFilesystemAction($import)->streamCsvFileToJsonlFile(
                        $csv,
                        $jsonl,
                        ProductsTypes::findOrFail($input['configuration']['product_type_id'])
                    );
                } catch (Throwable $exception) {
                    $rejected = str_contains($exception->getMessage(), '2147483647');
                }

                $this->assertTrue($rejected, 'Mapped resource outside the tenant must be rejected: ' . $resource);
            }
        } finally {
            @unlink($csv);
            @unlink($jsonl);
        }
    }

    public function testResolvesGlobalTypeNameWithoutCreatingDuplicate(): void
    {
        Queue::fake();
        $input = $this->input();
        $id = $input['configuration']['product_type_id'];
        $type = ProductsTypes::findOrFail($id);
        $type->update(['companies_id' => 0]);
        $count = ProductsTypes::count();
        $input['configuration'] = ['products_types' => $type->name];
        $response = $this->graphQL(self::MUTATION, ['input' => $input]);
        $response->assertJsonMissingPath('errors');
        $import = FilesystemImports::findOrFail($response->json('data.importProductFromFile.id'));
        $this->assertSame($id, $import->extra['inline_product_import']['configuration']['product_type_id']);
        $this->assertArrayNotHasKey('products_types', $import->extra['inline_product_import']['configuration']);
        $this->assertSame($count, ProductsTypes::count());
        $csv = tempnam(sys_get_temp_dir(), 'global-type-csv-');
        $jsonl = tempnam(sys_get_temp_dir(), 'global-type-jsonl-');
        $sku = 'global-vehicle-' . uniqid();
        file_put_contents($csv, "Slug,Name,Plate\n" . $sku . ",Global vehicle," . $sku . "\n");

        try {
            new ImportProductFromFilesystemAction($import)->streamCsvFileToJsonlFile($csv, $jsonl);
            $payload = json_decode(trim(file_get_contents($jsonl)), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($id, $payload['productType']['id']);
            $this->assertArrayNotHasKey('warehouses', $payload['variants'][0]);
            $product = new ProductImporterAction(
                ProductImporter::from($payload),
                $import->company,
                $import->user,
                $import->regions,
                $import->app,
                runWorkflow: false,
            )->execute();
            $this->assertSame($id, $product->fresh()->products_types_id);
            $warehouse = Warehouses::getDefault($import->company, $import->app);
            $variant = $product->variants()->firstOrFail();
            $this->assertTrue($variant->variantWarehouses()->where('warehouses_id', $warehouse->getId())->exists());
            $this->assertTrue($product->warehouses()->where('warehouses.id', $warehouse->getId())->exists());
            $this->assertSame($count, ProductsTypes::count());
        } finally {
            @unlink($csv);
            @unlink($jsonl);
        }
    }

    public function testResolvesCompanyTypeName(): void
    {
        Queue::fake();
        $input = $this->input();
        $id = $input['configuration']['product_type_id'];
        $type = ProductsTypes::findOrFail($id);
        $global = $type->replicate(['uuid']);
        $global->uuid = null;
        $global->companies_id = 0;
        $global->saveOrFail();
        $input['configuration'] = ['products_types' => $type->name];
        $response = $this->graphQL(self::MUTATION, ['input' => $input]);
        $response->assertJsonMissingPath('errors');
        $import = FilesystemImports::findOrFail($response->json('data.importProductFromFile.id'));
        $this->assertSame($id, $import->extra['inline_product_import']['configuration']['product_type_id']);
    }

    public function testCreatesMissingNamedTypeForCompany(): void
    {
        Queue::fake();
        $input = $this->input();
        $name = 'New vehicle type ' . uniqid();
        $input['configuration'] = ['products_types' => $name];
        $response = $this->graphQL(self::MUTATION, ['input' => $input]);
        $response->assertJsonMissingPath('errors');
        $import = FilesystemImports::findOrFail($response->json('data.importProductFromFile.id'));
        $type = ProductsTypes::findOrFail($import->extra['inline_product_import']['configuration']['product_type_id']);
        $this->assertSame($name, $type->name);
        $this->assertSame(auth()->user()->getCurrentCompany()->getId(), $type->companies_id);
    }

    public function testExplicitTypeIdTakesPrecedenceOverName(): void
    {
        Queue::fake();
        $input = $this->input();
        $id = $input['configuration']['product_type_id'];
        $count = ProductsTypes::count();
        $input['configuration']['products_types'] = 'Must not be created ' . uniqid();
        $response = $this->graphQL(self::MUTATION, ['input' => $input]);
        $response->assertJsonMissingPath('errors');
        $import = FilesystemImports::findOrFail($response->json('data.importProductFromFile.id'));
        $this->assertSame($id, $import->extra['inline_product_import']['configuration']['product_type_id']);
        $this->assertSame($count, ProductsTypes::count());
    }

    public function testRejectsMissingTypeIdAndName(): void
    {
        $input = $this->input();
        $input['configuration'] = ['channels_id' => 1];
        $this->graphQL(self::MUTATION, ['input' => $input])->assertJsonStructure(['errors']);
    }

    public function testSimpleProductMutationsReuseSharedNamedTypeResolution(): void
    {
        Queue::fake();
        $input = $this->input();
        $type = ProductsTypes::findOrFail($input['configuration']['product_type_id']);
        $sku = 'simple-type-' . uniqid();
        $response = $this->graphQL('mutation($input: ProductSimpleInput!) {
            createProductSimple(input: $input) { id }
        }', ['input' => [
            'name' => 'Simple vehicle',
            'slug' => $sku,
            'sku' => $sku,
            'products_types' => $type->name,
            'variants' => [],
        ]]);
        $response->assertJsonMissingPath('errors');
        $id = $response->json('data.createProductSimple.id');
        $this->assertNotNull($id, $response->getContent());
        $this->assertSame($type->getId(), Products::findOrFail($id)->products_types_id);
        $this->graphQL('mutation($id: ID!, $input: ProductSimpleUpdateInput!) {
            updateProductSimple(id: $id, input: $input) { id }
        }', ['id' => $id, 'input' => ['name' => 'Simple vehicle updated', 'products_types' => $type->name]])->assertJsonMissingPath('errors');
        $this->assertSame($type->getId(), Products::findOrFail($id)->products_types_id);
    }

    private function input(): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $type = ProductsTypes::create([
            'name' => 'Inline import ' . uniqid(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'weight' => 0,
        ]);
        $file = new Filesystem();
        $file->forceFill([
            'name' => 'fleet.csv',
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'path' => 'imports/fleet.csv',
            'url' => 'https://example.com/fleet.csv',
            'file_type' => 'csv',
            'size' => 100,
        ])->saveOrFail();

        return [
            'filesystem_id' => $file->getId(),
            'mapping' => ['handler' => 'Slug', 'product_name' => 'Name', 'variant_name' => 'Name', 'sku' => 'Plate'],
            'configuration' => ['product_type_id' => $type->getId()],
        ];
    }
}
