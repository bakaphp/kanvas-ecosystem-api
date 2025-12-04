<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\B2BSettingsEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PushProductToNetSuiteAction;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class NetSuitePushAllProductsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-push-products
                            {app_id}
                            {company_id}
                            {user_id}
                            {filePath}
                            {--delay=1000 : Delay in milliseconds between each product sync (default 1000ms = 1 second)}
                            {--skip=0 : Number of products to skip from the start}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Push product MOQ from CSV to NetSuite';

    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));
        $user = Users::getById($this->argument('user_id'));
        $failedProducts = [];
        $successfulProducts = [];

        $pushProductAction = new PushProductToNetSuiteAction(
            $app,
            $company,
            $user
        );

        $csvFilePath = $this->argument('filePath');

        $productList = $this->getProductList($csvFilePath);
        $barcodeList = array_keys($productList);
        $delayMs = (int) $this->option('delay');
        $delayMicroseconds = $delayMs * 1000; // Convert milliseconds to microseconds
        $skip = (int) $this->option('skip');

        // Skip products if requested
        if ($skip > 0) {
            $barcodeList = array_slice($barcodeList, $skip);
            $this->info("Skipping first {$skip} products...");
        }

        $totalProducts = count($barcodeList);
        $this->info("Pushing MOQ for {$totalProducts} products to NetSuite with {$delayMs}ms delay between each...");
        $this->output->progressStart($totalProducts);

        $currentIndex = $skip;
        foreach ($barcodeList as $barcode) {
            $code = (string) $barcode;
            $maxRetries = 3;
            $retryCount = 0;
            $success = false;

            while ($retryCount < $maxRetries && ! $success) {
                try {
                    $this->info("Processing product #{$currentIndex}: {$code}" . ($retryCount > 0 ? " (Retry {$retryCount})" : ""));

                    // Get the product data from CSV
                    $productData = $productList[$barcode];

                    $result = $pushProductAction->execute($code, $productData);

                    if ($result['success']) {
                        $successfulProducts[] = $code;
                        $success = true;
                    } else {
                        $errorMessage = $result['error'] ?? 'Unknown error';

                        // Check if it's a rate limit error
                        if (str_contains($errorMessage, 'concurrent request limit') || str_contains($errorMessage, 'Request blocked')) {
                            $retryCount++;
                            if ($retryCount < $maxRetries) {
                                $waitTime = $retryCount * 5; // 5, 10 seconds
                                $this->warn("Rate limit hit. Waiting {$waitTime} seconds before retry...");
                                sleep($waitTime);
                                continue;
                            }
                        }

                        $this->error("Failed to sync product #{$currentIndex} ({$code}): " . $errorMessage);
                        $failedProducts[] = $code;
                        break;
                    }
                } catch (Exception $e) {
                    $errorMessage = $e->getMessage();

                    // Check if it's a rate limit error
                    if (str_contains($errorMessage, 'concurrent request limit') || str_contains($errorMessage, 'Request blocked')) {
                        $retryCount++;
                        if ($retryCount < $maxRetries) {
                            $waitTime = $retryCount * 5; // 5, 10 seconds
                            $this->warn("Rate limit hit. Waiting {$waitTime} seconds before retry...");
                            sleep($waitTime);
                            continue;
                        }
                    }

                    $this->error("Error syncing product #{$currentIndex} ({$code}): " . $errorMessage);
                    $failedProducts[] = $code;
                    break;
                }
            }

            // Add throttling to respect NetSuite API rate limits
            if ($delayMicroseconds > 0) {
                usleep($delayMicroseconds);
            }

            $this->output->progressAdvance();
            $currentIndex++;
        }

        $this->output->progressFinish();

        // Display summary
        $this->info("\n=== Sync Summary ===");
        $this->info("Total products: {$totalProducts}");
        $this->info("Successful: " . count($successfulProducts));
        $this->info("Failed: " . count($failedProducts));

        if (count($failedProducts) > 0) {
            $this->error("\nFailed products: " . implode(', ', $failedProducts));
        }

        // Send notification
        $templateKey = $app->get(B2BSettingsEnums::B2B_SYNC_INVENTORY_EMAIL_TEMPLATE->getValue());

        if ($templateKey) {
            new SendUserNotificationAction(
                $app,
                $company,
                $user,
                RolesEnums::INVENTORY_MANAGER,
            )->execute($templateKey, [
                'total' => $totalProducts,
                'successful' => count($successfulProducts),
                'failed' => count($failedProducts),
            ]);
        }
    }

    /**
     * Parse the CSV file and return a list of products with all available data.
     *
     * Expected CSV headers: Copic Item No/ UPC, Description, Color Code, MOQ, Order Qty
     */
    private function getProductList(string $csvFilePath): array
    {
        $headerOffset = 0;
        $csv = Reader::createFromPath($csvFilePath);
        $csv->setHeaderOffset($headerOffset);
        $csv->skipEmptyRecords();
        $records = $csv->getRecords();

        $productList = [];
        foreach ($records as $offset => $record) {
            if ($offset < $headerOffset) {
                continue;
            }

            // Get barcode from primary column name
            $barcode = $record['Copic Item No/ UPC'] ?? null;

            if ($barcode) {
                $productList[$barcode] = [
                    // Basic product info
                    "barcode" => $barcode,
                    "name" => $record["Description"] ?? '',

                    // MOQ from CSV - this is the only field we're pushing
                    "minimum_order_quantity" => $record["MOQ"] ?? null,
                ];
            }
        }

        return $productList;
    }
}
