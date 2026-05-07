<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\DealerSocket;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Actions\PullLeadAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketCustomerService;
use Kanvas\Users\Models\Users;
use Throwable;

class DownloadAllLeadsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:dealersocket-download-all-leads 
                            {app_id : The application ID}
                            {company_id : Company ID to process}
                            {user_id : User ID to process the command}
                            {--items-per-letter=50 : Number of customers to process per letter}
                            {--start-from= : Start from specific letter (a-z)}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all leads from DealerSocket by searching customers alphabetically';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        // Check if company has DealerSocket configuration
        if (! $company->get(CustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value)) {
            $this->error('Company does not have DealerSocket configuration');

            return;
        }

        $itemsPerLetter = (int) $this->option('items-per-letter');
        $startFrom = $this->option('start-from');

        $this->info("Starting DealerSocket leads download for company: {$company->name}");
        $this->info("Processing up to {$itemsPerLetter} customers per letter");
        $this->newLine();

        $customerService = new DealerSocketCustomerService($app, $company);
        $pullLeadAction = new PullLeadAction($app, $company, $user);

        // Generate alphabet array
        $alphabet = range('a', 'z');

        // If start-from is specified, filter alphabet
        if ($startFrom) {
            $startFrom = strtolower($startFrom);
            if (strlen($startFrom) === 1 && ctype_alpha($startFrom)) {
                $startIndex = array_search($startFrom, $alphabet);
                if ($startIndex !== false) {
                    $alphabet = array_slice($alphabet, $startIndex);
                    $this->info("Starting from letter: {$startFrom}");
                }
            }
        }

        $totalCustomersProcessed = 0;
        $totalLeadsDownloaded = 0;
        $totalErrors = 0;

        // Create overall progress bar for letters
        $letterProgressBar = $this->output->createProgressBar(count($alphabet));
        $letterProgressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - Letter: %message%');

        foreach ($alphabet as $letter) {
            $letterProgressBar->setMessage(strtoupper($letter));

            try {
                // Search customers by first name starting with this letter
                $searchResponse = $customerService->searchCustomerByName(
                    $letter,
                    null,
                    true
                );

                if (! $searchResponse['success'] || empty($searchResponse['customers'])) {
                    $letterProgressBar->advance();

                    continue;
                }

                $customers = array_slice($searchResponse['customers'], 0, $itemsPerLetter);
                $customersCount = count($customers);
                $totalCustomersProcessed += $customersCount;

                $this->newLine();
                $this->info("Letter '{$letter}': Found {$customersCount} customers");

                // Process each customer
                $customerProgressBar = $this->output->createProgressBar($customersCount);
                $customerProgressBar->setFormat('  [%bar%] %current%/%max% - %message%');
                $customerProgressBar->setMessage('Processing...');

                foreach ($customers as $customer) {
                    try {
                        $customerId = $customer['entityId'] ?? null;

                        if (! $customerId) {
                            $customerProgressBar->setMessage('No entityId');
                            $customerProgressBar->advance();

                            continue;
                        }

                        // Pull leads for this customer
                        $leadsData = $pullLeadAction->execute(
                            null,
                            $customerId,
                            true
                        );

                        if (! empty($leadsData)) {
                            $totalLeadsDownloaded += count($leadsData);
                            $customerProgressBar->setMessage("Customer {$customerId}: " . count($leadsData) . ' leads');
                        } else {
                            $customerProgressBar->setMessage("Customer {$customerId}: No leads");
                        }

                        $customerProgressBar->advance();
                    } catch (Throwable $e) {
                        $totalErrors++;
                        $customerProgressBar->setMessage("Error: {$e->getMessage()}");
                        $customerProgressBar->advance();
                        $this->newLine();
                        $this->warn('Failed to process customer: ' . $e->getMessage());
                    }
                }

                $customerProgressBar->finish();
                $this->newLine();
            } catch (Throwable $e) {
                $this->newLine();
                $this->error("Failed to process letter '{$letter}': " . $e->getMessage());
                $totalErrors++;
            }

            $letterProgressBar->advance();
        }

        $letterProgressBar->finish();
        $this->newLine(2);

        // Final summary
        $this->info('=== Download Summary ===');
        $this->info("Total customers processed: {$totalCustomersProcessed}");
        $this->info("Total leads downloaded: {$totalLeadsDownloaded}");
        $this->info("Total errors: {$totalErrors}");
        $this->info('Download completed!');
    }
}
