<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\Ecosystem\Integration\Imports\Concerns\CreatesImportSources;
use Tests\TestCase;

/**
 * The Dealer template's own mapping, through the real transform and the real importer, into the
 * database. The other tests stop at the JSONL payload, so a mapped value whose *type* the importer
 * rejects passes them and fails every row of a real import — `VariantsWarehouses` types `is_new` as
 * `bool`, not 1/0. Anything the template maps has to survive this test.
 */
final class DealerTemplateEndToEndTest extends TestCase
{
    use CreatesImportSources;
    use DatabaseTransactions;
    use ProductImportFixtures;

    protected $connectionsToTransact = [null, 'ecosystem'];

    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testTheDealerTemplateImportsANewAndAUsedCarIntoTheDatabase(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = $this->importUser();
        $company = $user->getCurrentCompany();
        $this->setupInventory($app, $company, $user);

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();
        $channel = Channels::fromApp($app)->fromCompany($company)->firstOrFail();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();
        $newVin = 'VINNEW' . uniqid();
        $usedVin = 'VINUSED' . uniqid();

        // Photo columns stay empty on purpose: attaching files downloads them.
        $csvPath = $this->writeCsv($this->dealerCsv(
            [$newVin, 'S1', 'N', '2026', 'Cadillac', 'XT6', 'Sport', '62000', '59900', ''],
            [$usedVin, 'S2', 'U', '2020', 'Honda', 'Civic', '', '', '18500', ''],
        ));

        $records = $this->transform(
            $csvPath,
            $this->dealerMapper(),
            $warehouse,
            $channel
        );

        foreach ($records as $record) {
            new ProductImporterAction(
                ProductImporter::from($record),
                $company,
                $user,
                $region,
                $app,
                false
            )->execute();
        }

        $new = Variants::where('sku', $newVin)->where('companies_id', $company->getId())->first();
        $used = Variants::where('sku', $usedVin)->where('companies_id', $company->getId())->first();

        $this->assertNotNull($new, 'The new car was imported');
        $this->assertNotNull($used, 'The used car was imported');
        $this->assertSame('Cadillac XT6 2026 Sport', $new->product->name);
        $this->assertSame('Honda Civic 2020', $used->product->name);
        $this->assertSame($newVin, $new->product->slug);

        $newWarehouse = $new->variantWarehouses()->first();
        $usedWarehouse = $used->variantWarehouses()->first();
        $this->assertSame($warehouse->getId(), (int) $newWarehouse->warehouses_id, 'The source warehouse, not the default');
        $this->assertTrue((bool) $newWarehouse->is_new, 'New/Used "N" is a new car');
        $this->assertFalse((bool) $usedWarehouse->is_new, 'New/Used "U" is a used car');
        $this->assertEquals(62000, (float) $newWarehouse->price, 'MSRP wins over Price');
        $this->assertEquals(18500, (float) $usedWarehouse->price, 'Price is used when MSRP is empty');

        $channelRow = VariantsChannels::where('products_variants_id', $new->getId())
            ->where('channels_id', $channel->getId())
            ->first();
        $this->assertNotNull($channelRow, 'The variant landed in the source channel');
        $this->assertSame(1, (int) $channelRow->is_published, 'A re-import must leave the car published');
        $this->assertSame($warehouse->getId(), (int) $channelRow->warehouses_id);

        $attributes = collect($new->attributes)->mapWithKeys(fn ($a) => [$a->name => (string) $a->value]);
        $this->assertSame('S1', $attributes['stock_number'] ?? null);
        $this->assertSame('Cadillac', $attributes['make'] ?? null);
        $this->assertSame('1', $attributes['new'] ?? null);
        $this->assertSame('Sport', $attributes['series'] ?? null);
    }

    /**
     * @return list<array<string, mixed>> the records the importer receives for this CSV
     */
    private function transform(
        string $csvPath,
        FilesystemMapper $mapper,
        Warehouses $warehouse,
        Channels $channel
    ): array {
        $user = $this->importUser();
        $company = $user->getCurrentCompany();

        $filesystem = new Filesystem([
            'users_id' => $user->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => basename($csvPath),
            'path' => '/test/' . basename($csvPath),
            'url' => 'https://example.test/feed.csv',
            'size' => (string) filesize($csvPath),
            'file_type' => 'csv',
        ]);
        $filesystem->save();

        $import = new FilesystemImports();
        $import->apps_id = app(Apps::class)->getId();
        $import->users_id = $user->getId();
        $import->companies_id = $company->getId();
        $import->companies_branches_id = $user->getCurrentBranch()->getId();
        $import->regions_id = Regions::fromApp(app(Apps::class))->fromCompany($company)->firstOrFail()->getId();
        $import->filesystem_id = $filesystem->getId();
        $import->filesystem_mapper_id = $mapper->getId();
        $import->status = 'pending';
        $import->is_deleted = 0;
        $import->extra = [
            'warehouse_id' => $warehouse->getId(),
            'channels_id' => $channel->getId(),
            'deleteAfterUse' => false,
        ];
        $import->saveOrFail();

        return $this->transformToImporterRows($import, $csvPath, $filesystem);
    }

    private function writeCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dealer') . '.csv';
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }
}
