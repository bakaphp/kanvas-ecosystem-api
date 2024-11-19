<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\DownloadAllZohoLeadAction;
use Kanvas\Guild\Leads\Models\LeadReceiver;

class ZohoLeadsDownloadCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:guild-zoho-lead-sync {app_id} {company_id} {receiver_id} {page=50} {leadsPerPage=200}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all leads from Zoho to this branch';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $leadReceiver = LeadReceiver::getByIdFromCompanyApp((int) $this->argument('receiver_id'), $company, $app);
        $page = (int) $this->argument('page');
        $leadsPerPage = (int) $this->argument('leadsPerPage');

        $downloadAllLeads = new DownloadAllZohoLeadAction($app, $company, $leadReceiver);
        $totalLeads = $page * $leadsPerPage;

        // Initialize the progress bar
        $this->output->progressStart($totalLeads);

        $leads = $downloadAllLeads->execute($page, $leadsPerPage);

        foreach ($leads as $lead) {
            // Process the lead and advance the progress bar
            $this->output->progressAdvance();
        }

        // Finish the progress bar
        $this->output->progressFinish();

        $this->info(PHP_EOL . $downloadAllLeads->getTotalLeadsProcessed() . ' leads downloaded from Zoho to ' . $leadReceiver->name);

        return;
    }
}
