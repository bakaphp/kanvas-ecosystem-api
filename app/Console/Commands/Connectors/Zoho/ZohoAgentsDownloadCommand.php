<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\DownloadAllZohoAgentAction;

class ZohoAgentsDownloadCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:zoho-agents-sync {app_id} {company_id} {module=Agents} {page=60} {agentsPerPage=200}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all agents from Zoho to this branch';

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
        $module = $this->argument('module');
        $page = (int) $this->argument('page');
        $agentsPerPage = (int) $this->argument('agentsPerPage');

        $downloadAllAgents = new DownloadAllZohoAgentAction($app, $company, $module);
        $totalAgents = $page * $agentsPerPage;

        // Initialize the progress bar
        $this->output->progressStart($totalAgents);

        $agents = $downloadAllAgents->execute($page, $agentsPerPage);

        foreach ($agents as $agent) {
            // Process the agent and advance the progress bar
            $this->output->progressAdvance();
        }

        // Finish the progress bar
        $this->output->progressFinish();

        $this->info(PHP_EOL . $downloadAllAgents->getTotalAgentsProcessed() . ' agents downloaded from Zoho module ' . $module);

        return;
    }
}
