<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;

class NetSuiteSyncAllProductStockCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-sync-all-stock {app_id} {company_id} {user_id} {--delay=100 : Delay in milliseconds between each product sync} {--skip=0 : Number of products to skip from the start} {--limit= : Maximum number of products to sync} {--email= : Email address to send summary report to}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all Kanvas product stock availability from NetSuite';

    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));
        $user = Users::getById($this->argument('user_id'));

        $syncNetSuiteProduct = new PullNetSuiteProductPriceAction(
            $app,
            $company,
            $user
        );

        $delayMs = (int) $this->option('delay');
        $delayMicroseconds = $delayMs * 1000; // Convert milliseconds to microseconds
        $skip = (int) $this->option('skip');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        // Get all variants from the company that have barcodes
        $variantsQuery = Variants::fromApp($app)
            ->fromCompany($company)
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->where('is_deleted', 0);

        if ($skip > 0) {
            $variantsQuery->skip($skip);
        }

        if ($limit) {
            $variantsQuery->take($limit);
        }

        $variants = $variantsQuery->get();
        $totalProducts = $variants->count();

        if ($totalProducts === 0) {
            $this->info('No variants with barcodes found to sync.');

            return;
        }

        $this->info("Syncing {$totalProducts} variants with {$delayMs}ms delay between each...");
        $this->output->progressStart($totalProducts);

        $missingProducts = [];
        $syncedProducts = [];
        $errorProducts = [];
        $currentIndex = $skip;

        foreach ($variants as $variant) {
            try {
                $barcode = (string) $variant->barcode;

                $this->info("Processing variant #{$currentIndex}: {$variant->name} (Barcode: {$barcode})");

                $result = $syncNetSuiteProduct->execute($barcode);

                if (isset($result['error'])) {
                    $this->error("Error syncing variant #{$currentIndex} ({$barcode}): " . $result['error']);
                    $errorProducts[] = [
                        'barcode' => $barcode,
                        'name' => $variant->name,
                        'error' => $result['error'],
                    ];
                } else {
                    $syncedProducts[] = [
                        'barcode' => $barcode,
                        'name' => $variant->name,
                        'quantity' => $result['product']['quantity_available'] ?? 0,
                    ];
                    $this->info("✓ Synced: {$variant->name} - Qty: " . ($result['product']['quantity_available'] ?? 0));
                }

                // Add throttling to respect NetSuite API rate limits
                if ($delayMicroseconds > 0) {
                    usleep($delayMicroseconds);
                }
            } catch (Exception $e) {
                $this->error("Error syncing variant #{$currentIndex} ({$variant->name}): " . $e->getMessage());
                $errorProducts[] = [
                    'barcode' => $variant->barcode,
                    'name' => $variant->name,
                    'error' => $e->getMessage(),
                ];
                usleep($delayMicroseconds);
            }

            $this->output->progressAdvance();
            $currentIndex++;
        }

        $this->output->progressFinish();

        // Summary
        $runDate = Carbon::now()->format('Y-m-d H:i:s T');

        $this->newLine();
        $this->info("=== Sync Summary ({$runDate}) ===");
        $this->info("Total variants processed: {$totalProducts}");
        $this->info('Successfully synced: ' . count($syncedProducts));
        $this->info('Errors: ' . count($errorProducts));

        if (count($syncedProducts) > 0) {
            $this->newLine();
            $this->info('=== Successfully Synced Products ===');
            $this->table(
                ['Barcode', 'Name', 'Quantity'],
                array_map(fn ($item) => [
                    $item['barcode'],
                    $item['name'],
                    $item['quantity'],
                ], $syncedProducts)
            );
        }

        if (count($errorProducts) > 0) {
            $this->newLine();
            $this->error('=== Products with Errors ===');
            $this->table(
                ['Barcode', 'Name', 'Error'],
                array_map(fn ($item) => [
                    $item['barcode'],
                    $item['name'],
                    $item['error'],
                ], $errorProducts)
            );
        }

        // Send email summary
        /** @var string|null $email */
        $email = $this->option('email');
        if ($email !== null && $email !== '') {
            $summaryBody = "NetSuite Stock Sync Summary - {$runDate}\n";
            $summaryBody .= "Company: {$company->name}\n\n";
            $summaryBody .= "Total variants processed: {$totalProducts}\n";
            $summaryBody .= 'Successfully synced: ' . count($syncedProducts) . "\n";
            $summaryBody .= 'Errors: ' . count($errorProducts) . "\n";

            if (count($syncedProducts) > 0) {
                $summaryBody .= "\n--- Successfully Synced Products ---\n";
                foreach ($syncedProducts as $item) {
                    $summaryBody .= "  {$item['barcode']} | {$item['name']} | Qty: {$item['quantity']}\n";
                }
            }

            if (count($errorProducts) > 0) {
                $summaryBody .= "\n--- Products with Errors ---\n";
                foreach ($errorProducts as $item) {
                    $summaryBody .= "  {$item['barcode']} | {$item['name']} | Error: {$item['error']}\n";
                }
            }

            Mail::raw($summaryBody, function (\Illuminate\Mail\Message $message) use ($email, $runDate, $company) {
                $message->to($email)
                    ->subject("NetSuite Stock Sync Summary - {$company->name} - {$runDate}");
            });

            $this->info("Summary email sent to {$email}");
        }

        $this->newLine();
        $this->info('Stock sync completed!');
    }
}
