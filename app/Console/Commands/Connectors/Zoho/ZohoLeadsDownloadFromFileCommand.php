<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use League\Csv\Reader;

class ZohoLeadsDownloadCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:guild-zoho-lead-file-sync {app_id} {company_id} {receiver_id} {file}';

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
        $receiver = LeadReceiver::getByIdFromCompanyApp((int) $this->argument('receiver_id'), $company, $app);
        $file = $this->argument('file');

        // Read CSV file
        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0);
        $header = $csv->getHeader(); //returns the CSV header record
        $records = $csv->getRecords(); //returns all the CSV records as an Iterator object

        $totalRecords = count(iterator_to_array($csv->getRecords()));

        if ($totalRecords === 0) {
            $this->error('The provided file has no records.');

            return Command::FAILURE;
        }

        $this->info("Syncing {$totalRecords} leads from file...");

        // Create progress bar
        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();
        $i = 0;

        foreach ($records as $record) {
            $leadId = str_replace('zcrm_', '', $record['Record Id']);

            $syncZohoLead = new SyncZohoLeadAction(
                $app,
                $company,
                $receiver,
                $leadId
            );

            $localLead = $syncZohoLead->execute();
            $progressBar->advance();
            if (! $localLead) {
                continue;
            }
            $i++;
        }

        // Finish the progress bar
        $this->output->progressFinish();

        $this->info(PHP_EOL . "Synced {$i} leads from file.");

        return;
    }
}
