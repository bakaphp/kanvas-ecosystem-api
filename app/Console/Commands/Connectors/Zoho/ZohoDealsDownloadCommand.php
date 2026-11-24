<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\DownloadAllZohoDealAction;
use Kanvas\Users\Models\Users;

class ZohoDealsDownloadCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:guild-zoho-deal-sync
                            {app_id}
                            {company_id}
                            {page=50 : How many Zoho pages to walk}
                            {dealsPerPage=200}
                            {--user_id= : Kanvas user the deals are created as; defaults to the company owner}';

    protected $description = 'Download all deals from Zoho into this company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = $this->option('user_id') !== null
            ? Users::getById((int) $this->option('user_id'))
            : $company->user;
        $totalPages = (int) $this->argument('page');
        $dealsPerPage = (int) $this->argument('dealsPerPage');

        $download = new DownloadAllZohoDealAction($app, $company, $user);

        $this->output->progressStart($totalPages * $dealsPerPage);

        foreach ($download->execute($totalPages, $dealsPerPage) as $deal) {
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->info(PHP_EOL . $download->getTotalDealsProcessed() . ' deals downloaded from Zoho to ' . $company->name);

        return self::SUCCESS;
    }
}
