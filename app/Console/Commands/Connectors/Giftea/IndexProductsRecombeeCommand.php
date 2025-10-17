<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Giftea;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Giftea\Services\RecombeeItemService;
use Kanvas\Inventory\Variants\Models\Variants;
use Throwable;

class IndexProductsRecombeeCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:giftea-index-recombee-product {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Index products to recombee';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);


        $query = Variants::fromApp($app)->notDeleted()->orderBy('id', 'asc');
        $cursor = $query->cursor();
        $totalProducts = $query->count();
        $this->output->progressStart($totalProducts);
        $productIndex = new RecombeeItemService($app);
        $productIndex->createProductDatabase();

        foreach ($cursor as $variant) {
            try {
                $result = $productIndex->indexProductVariant($variant);
                $this->info('variant ID: ' . $variant->getId() . ' indexed with result: ' . $result);
                $this->output->progressAdvance();
            } catch (Throwable $e) {
                $this->output->error($e->getMessage());
            }
        }

        $this->output->progressFinish();

        return;
    }
}
