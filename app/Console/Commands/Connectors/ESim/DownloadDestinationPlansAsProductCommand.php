<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ESim\Actions\DownloadPlanToProductAction;
use Kanvas\Regions\Models\Regions;

class DownloadDestinationPlansAsProductCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:esim-connector-download-destination-plans {app_id} {company_id} {region_id} {destination_plans} {warehouse_id?} {channel_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all destination plan as products example: [{\"code\":\"us\",\"limit\":25,\"page\":1}] ';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $region = Regions::getById((int) $this->argument('region_id'), $app);

        $downloadPlanProducts = new DownloadPlanToProductAction(
            $region,
            $region->company->user
        );

        $downloadPlanProducts->execute(json_decode($this->argument('destination_plans'), true));

        return;
    }
}
