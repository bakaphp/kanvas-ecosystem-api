<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Actions\DailyUsageReportAction;

class GuildDailyReportCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-guild:daily-report {app_id?} {company_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send daily report to the guild';

    public function handle(): void
    {
        //@todo make this run for multiple apps by looking for them at apps settings flag
        $app = Apps::getById($this->argument('app_id') );
        $company = Companies::getById($this->argument('company_id'));
        $this->overwriteAppService($app); 

        //for now just apollo, but this should be for sending all the different reports
        $this->info('Sending Apollo Daily Report - '. date('Y-m-d'));
        $apolloDailyReport = new DailyUsageReportAction($app, $company);
        $result = $apolloDailyReport->execute();
        $this->info('Total report send ' . count($result));
    }
}
