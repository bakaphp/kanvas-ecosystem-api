<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Zoho;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\SyncZohoAgentAction;
use League\Csv\Reader;

class ZohoAgentsDownloadFromFileCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:zoho-agents-file-sync {app_id} {company_id} {module=Agents} {file?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all agents from Zoho file to this branch';

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
        $file = $this->argument('file');

        // Read CSV file
        $csv = Reader::createFromPath($file, 'r');
        $csv->setHeaderOffset(0);
        $records = iterator_to_array($csv->getRecords()); // Convert to array for counting
        $totalRecords = count($records);

        if ($totalRecords === 0) {
            $this->error('The provided file has no records.');

            return Command::FAILURE;
        }

        $this->info("Syncing {$totalRecords} agents from file...");

        // Create progress bar
        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();

        foreach ($records as $record) {
            $email = $record['Email'];

            try {
                $syncZohoAgent = new SyncZohoAgentAction(
                    $app,
                    $company,
                    $email
                );

                $syncZohoAgent->execute();
            } catch (Exception $e) {
                Log::error('Error syncing Zoho agent: ' . $e->getMessage());

                continue;
            }

            // Advance the progress bar
            $progressBar->advance();
        }

        // Finish the progress bar
        $progressBar->finish();
        $this->newLine(); // Add a new line after the progress bar

        $this->info('Sync completed successfully.');

        return Command::SUCCESS;
    }
}
