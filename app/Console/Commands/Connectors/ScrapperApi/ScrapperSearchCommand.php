<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class ScrapperSearchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:scrapper-search {app_id} {userId} {branch_id} {region_id} {search}';

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
        $branch = CompaniesBranches::getById((int) $this->argument('branch_id'));
        $regions = Regions::getById((int) $this->argument('region_id'));
        $user = Users::getById((int) $this->argument('userId'));

        $action = new ScrapperAction(
            $app,
            $user,
            $branch,
            $regions,
            $this->argument('search')
        );
        $action->execute();

        return;
    }
}
