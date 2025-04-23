<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperProcessorAction;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class IndexCsvCommand extends Command
{
    protected $signature = 'kanvas:scrapper-search {app_id} {userId} {branch_id} {region_id} {url}';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->argument('url');
        $fileName = Str::uuid().'.csv';

        $response = Http::get($url);

        if ($response->successful()) {
            Storage::put("downloads/{$fileName}", $response->body());
            $path = Storage::path("downloads/{$fileName}");
        } else {
            $this->error('Failed to download the file.');
        }
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        $reader->setEscape('');

        $records = $reader->getRecords();
        $collection = collect(value: $records);
        foreach ($collection as $record) {
            $app = Apps::getById((int) $this->argument('app_id'));
            $branch = CompaniesBranches::getById((int) $this->argument('branch_id'));
            $regions = Regions::getById((int) $this->argument('region_id'));
            $user = Users::getById((int) $this->argument('userId'));
            if (preg_match('/(?:dp|gp\/product)\/([A-Z0-9]{10})/', $record['Product Link-href'], $matches)) {
                $asin = $matches[1];
            } else {
                $this->info('Asin not foundsin not found');
            }

            try {
                $repository = new ScrapperRepository($app);
                $this->info('Asin: '.$asin);
                $product = $repository->getByAsin($asin);
                $product['asin'] = $asin;

                if (! key_exists('pricing', $product)) {
                    continue;
                }

                if (key_exists('list_price', $product)) {
                    $product['original_price'] = [
                        'price' => $product['list_price'],
                    ];
                }
                $product['price'] = str_replace('$', '', $product['pricing']);
                $product['image'] = $product['images'][0];
                $product['asin'] = $asin;

                // $action = new ScrapperAction(
                //     $app,
                //     $user,
                //     $branch,
                //     $regions,
                //     $asin
                // );
                $action = (new ScrapperProcessorAction(
                    $app,
                    $user,
                    $branch,
                    $regions,
                    [$product],
                    null
                ));
                $action->execute();
                $scrapperProducts = $app->get('scrapperProducts');
                $scrapperProducts = $scrapperProducts ? $scrapperProducts : [];
                $scrapperProducts[] = $asin;
                $app->set('scrapperProducts', json_encode($scrapperProducts));
            } catch (\Throwable $e) {
                $this->error('Error: '.$e->getMessage());
                $scrapperProducts = $app->get('failedScrapperProducts');
                $scrapperProducts = $scrapperProducts ? $scrapperProducts : [];
                $scrapperProducts[] = $asin;
                $app->set('failedScrapperProducts', json_encode($scrapperProducts));
            }
        }
    }
}
