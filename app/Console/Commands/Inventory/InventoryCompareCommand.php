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
        $this->compareInventory($app, $company, $csvFilePath);
    }

    protected function compareInventory(AppInterface $app, CompanyInterface $company, string $csvFilePath): void
    {
        $barcodeList = $this->getBarcodeList($csvFilePath);
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
                ...array_values(array_diff($chunk, $foundVariants))
            ];
        }
        $this->info('Missing variants in ' . $company->name . ': ' . json_encode($missingVariants));
        $this->info('Total missing variants in ' . $company->name . ': ' . count($missingVariants));
        Storage::disk('local')->put('missing_variants.json', json_encode($missingVariants));
    }


    private function getBarcodeList(string $csvFilePath): array
    {
        $barcodeList = [];
        $headerOffset = 0;
        $csv = Reader::createFromPath($csvFilePath);
        $csv->setHeaderOffset($headerOffset);
        $csv->skipEmptyRecords();
        $records = $csv->getRecords();
        foreach ($records as $offset => $record) {
            if ($offset < $headerOffset) {
                continue;
            }

            $barcodeList[] = $record['Copic Item No/ UPC'];
        }

        return $barcodeList;
    }
}
