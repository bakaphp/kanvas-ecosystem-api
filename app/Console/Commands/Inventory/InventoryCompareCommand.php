<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use League\Csv\Reader;

class InventoryCompareCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:compare {app_id} {company_id} {filePath}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Compare inventory for the company';

    public function handle(): void
    {
        //@todo make this run for multiple apps by looking for them at apps settings flag
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));

        $csvFilePath = $this->argument('filePath');

        $productList = $this->getProductList($csvFilePath);
        $barcodeList = array_keys($productList);
        $inverseProductList = array_flip($productList);

        $missingBarcodes = $this->compareInventory($app, $company, $barcodeList);
        $skusOfMissingBarcodes = collect($missingBarcodes)->map(fn ($barcode) => $productList[$barcode])->toArray();

        ['missing_skus' => $missingSkus, 'changed_barcodes' => $changedBarcodes] = $this->compareInventoryBySku($app, $company, $skusOfMissingBarcodes);
        $barcodesOfMissingSkus = collect($missingSkus)->map(fn ($sku) => $inverseProductList[$sku])->toArray();
        $barcodesOfChangedBarcodes = collect($changedBarcodes)->map(fn ($barcode) => $inverseProductList[$barcode])->toArray();

        $this->info('Missing barcodes: '.json_encode($missingBarcodes));

        $this->info('Missing variants by barcode '.$company->name.': '.json_encode($missingBarcodes));
        $this->info('Total missing variants in '.$company->name.': '.count($missingBarcodes));
        $this->info('Total changed barcodes in '.$company->name.': '.count($changedBarcodes));
        $this->info('Total missing skus in '.$company->name.': '.count($missingSkus));
        Storage::disk('local')->put('missing_variants.json', json_encode([
            'missing_barcodes' => $missingBarcodes,
            'changed_barcodes' => $barcodesOfChangedBarcodes,
            'missing_skus'     => $barcodesOfMissingSkus,
        ]));
    }

    protected function compareInventory(AppInterface $app, CompanyInterface $company, array $barcodeList): array
    {
        $chunks = array_chunk($barcodeList, 50);
        $missingVariants = [];

        foreach ($chunks as $chunk) {
            $foundVariants = Variants::query()
            ->where(
                fn ($query) => $query
                ->whereIn('barcode', $chunk)
                ->orWhereIn('ean', $chunk)
            )
            ->where('companies_id', $company->id)
            ->where('apps_id', $app->id)
            ->pluck('barcode')
            ->toArray();

            $missingVariants = [
                ...$missingVariants,
                ...array_values(array_diff($chunk, $foundVariants)),
            ];
        }

        return $missingVariants;
    }

    protected function compareInventoryBySku(AppInterface $app, CompanyInterface $company, array $skuList): array
    {
        $chunks = array_chunk($skuList, 50);
        $missingSkus = [];
        $changedSkus = [];

        foreach ($chunks as $chunk) {
            $foundVariants = Variants::query()
            ->where(
                fn ($query) => $query
                ->whereIn('sku', $chunk)
            )
            ->where('companies_id', $company->id)
            ->where('apps_id', $app->id)
            ->pluck('sku')
            ->toArray();

            $missingSkus = [
                ...$missingSkus,
                ...array_values(array_diff($chunk, $foundVariants)),
            ];

            $changedSkus = [
                ...$changedSkus,
                ...$foundVariants,
            ];
        }

        return [
            'missing_skus'     => $missingSkus,
            'changed_barcodes' => $foundVariants,
        ];
    }

    private function getProductList(string $csvFilePath): array
    {
        $headerOffset = 0;
        $csv = Reader::createFromPath($csvFilePath);
        $csv->setHeaderOffset($headerOffset);
        $csv->skipEmptyRecords();
        $records = $csv->getRecords();

        $productList = [];
        foreach ($records as $offset => $record) {
            if ($offset < $headerOffset) {
                continue;
            }

            $barcode = $record['Copic Item No/ UPC'];
            $sku = $record['Macpherson  Item #'];
            $productList[$barcode] = $sku;
        }

        return $productList;
    }
}
