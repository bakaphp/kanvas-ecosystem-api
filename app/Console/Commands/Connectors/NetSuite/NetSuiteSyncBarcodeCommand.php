<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Variants\Services\VariantService;
use League\Csv\Reader;

class NetSuiteSyncBarcodeCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-sync-barcodes {app_id} {company_id} {filePath}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync inventory for the company';

    public function handle(): void
    {
        //@todo make this run for multiple apps by looking for them at apps settings flag
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));
        $productService = new NetSuiteProductService($app, $company);

        $csvFilePath = $this->argument('filePath');

        $productList = $this->getProductList($csvFilePath);
        $barcodeList = array_keys($productList);

        $missingBarcodes = VariantService::compareInventory($app, $company, $barcodeList);

        $this->info('Missing variants by barcode '.$company->name.': '.count($missingBarcodes));
        echo PHP_EOL;

        $productWithSkus = $productService->pullNetsuiteProductsSku($missingBarcodes);
        $productWithSkus = collect($missingBarcodes)->map(
            fn ($barcode) => [
                ...$productList[$barcode],
                'sku' => $productWithSkus[$barcode],
            ]
        )->toArray();

        ['missing_skus' => $missingSkus, 'changed_barcodes' => $changedBarcodes] = VariantService::updateVariantBySku($app, $company, $productWithSkus);

        $this->info('Missing variants by barcode '.$company->name.': '.json_encode($missingSkus));
        echo PHP_EOL;
        $this->info('Total missing variants in '.$company->name.': '.count($missingSkus));
        echo PHP_EOL;
        $this->info('Changed barcodes '.$company->name.': '.json_encode($changedBarcodes));
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
            $productList[$barcode] = [
                'sku'     => $sku,
                'name'    => $record['Description'],
                'barcode' => $barcode,
            ];
        }

        return $productList;
    }
}
