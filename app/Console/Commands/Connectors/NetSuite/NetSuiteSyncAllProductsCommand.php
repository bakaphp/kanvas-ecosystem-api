<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Variants\Services\VariantService;
use League\Csv\Reader;

class NetSuiteSyncAllProductsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-sync-products {app_id} {company_id} {filePath}';

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
        $companyUser = $company->users()->first();

        $syncNetSuiteProduct = new PullNetSuiteProductPriceAction(
            $app,
            $company,
            $companyUser
        );

        $csvFilePath = $this->argument('filePath');

        $productList = $this->getProductList($csvFilePath);
        $barcodeList = array_keys($productList);
        $this->output->progressStart(count($barcodeList));
        foreach ($barcodeList as $barcode) {
            $code = (string) $barcode;
            $syncNetSuiteProduct->execute($code);
            $this->output->progressAdvance();
        }
        $this->output->progressFinish();
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
            $sku = $record["Macpherson  Item #"];
            $productList[$barcode] = [
                "sku" => $sku,
                "name" => $record["Description"],
                "barcode" => $barcode
            ];
        }

        return $productList;
    }
}
