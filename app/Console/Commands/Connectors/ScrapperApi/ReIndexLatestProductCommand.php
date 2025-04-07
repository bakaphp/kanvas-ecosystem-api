<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Jobs\IndexProductJob;
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
    protected $signature = 'kanvas:products-scrapper {app_id} {userId} {branch_id} {region_id} {limit?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download products from shopify to this warehouse';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $branch = CompaniesBranches::getById((int) $this->argument('branch_id'));
        $regions = Regions::getById((int) $this->argument('region_id'));
        $user = Users::getById((int) $this->argument('userId'));
        $limit = $this->argument('limit') ?? 2000;
        IndexProductJob::dispatch(
            $app,
            $branch,
            $regions,
            $user,
            $limit
        );
    }
}
