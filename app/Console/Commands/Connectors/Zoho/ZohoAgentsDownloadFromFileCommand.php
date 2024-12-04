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
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
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
        //ini_set('memory_limit', '1512M');

        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $module = $this->argument('module');
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

        $this->info("Syncing {$totalRecords} agents from file...");

        // Create progress bar
        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();
        $i = 0;

        foreach ($records as $record) {
            $email = $record['Email'];

            $user = Users::where('email', $email)->first();
            $progressBar->advance();

            if ($user && Agent::where('users_id', $user->getId())->fromApp($app)->exists()) {
                $i++;

                continue;
            }

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

            $i++;
        }

        // Finish the progress bar
        $progressBar->finish();
        $this->newLine(); // Add a new line after the progress bar

        $this->info('Sync completed successfully.');

        return Command::SUCCESS;
    }
}
