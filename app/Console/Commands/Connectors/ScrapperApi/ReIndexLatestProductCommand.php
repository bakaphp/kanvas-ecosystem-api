<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperProcessorAction;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class ReIndexLatestProductCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:products-scrapper {app_id} {userId} {branch_id} {region_id} {limit?} {order?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download products from shopify to this warehouse';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $branch = CompaniesBranches::getById((int) $this->argument('branch_id'));
        $regions = Regions::getById((int) $this->argument('region_id'));
        $user = Users::getById((int) $this->argument('userId'));
        $limit = $this->argument('limit') ?? 2000;
        $order = $this->argument('order') ?? 'desc';
        $products = Products::where('apps_id', $app->getId())
            ->orderBy('id', $order)
            ->limit($limit)
            ->get();
        foreach ($products as $product) {
            $this->info('Processing product: ' . $product->id);
            foreach ($product->variants as $variant) {
                $this->info('Variant: ' . $variant->id);
                try {
                    $repository = new ScrapperRepository($app);
                    $productScrapped = $repository->getByAsin($variant->sku);
                    $productScrapped['asin'] = $variant->sku;

                    $productScrapped['price'] = str_replace('$', '', $productScrapped['pricing']);
                    $productScrapped['image'] = $productScrapped['images'][0];

                    $action = (new ScrapperProcessorAction(
                        $app,
                        $user,
                        $branch,
                        $regions,
                        [$productScrapped],
                        null
                    ));
                    $action->execute();
                } catch (\Exception $e) {
                    $this->error('Error processing variant: ' . $variant->id . ' - ' . $e->getMessage());
                    $variant->delete();
                }
            }
        }
    }
}
