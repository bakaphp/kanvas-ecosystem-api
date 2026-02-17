<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;

class BackfillOrderProvidersCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'order-providers:backfill
                            {--app= : The app ID to backfill orders for}
                            {--order-type= : The order type ID to filter orders}';

    /**
     * The console command description.
     */
    protected $description = 'Backfill order_providers table with provider companies from existing orders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appId = $this->option('app');
        $orderTypeId = $this->option('order-type');

        if (! $appId) {
            $this->error('App ID is required. Use --app=<app_id>');

            return self::FAILURE;
        }

        $app = Apps::find($appId);

        if (! $app) {
            $this->error("App with ID {$appId} not found");

            return self::FAILURE;
        }

        $platformCompanyId = $app->get('B2B_MAIN_COMPANY_ID');

        if (! $platformCompanyId) {
            $this->error("B2B_MAIN_COMPANY_ID not configured for app {$appId}");

            return self::FAILURE;
        }

        $this->info("Starting backfill for app {$appId}...");
        if ($orderTypeId) {
            $this->info("Filtering by order type: {$orderTypeId}");
        }

        $query = DB::connection('commerce')
            ->table('orders')
            ->where('apps_id', $appId)
            ->orderBy('id');

        if ($orderTypeId) {
            $query->where('order_types_id', $orderTypeId);
        }

        $totalOrders = $query->count();
        $this->info("Found {$totalOrders} orders to process");

        $bar = $this->output->createProgressBar($totalOrders);
        $bar->start();

        $processed = 0;
        $skipped = 0;

        $query->chunk(100, function ($orders) use ($platformCompanyId, &$processed, &$skipped, $bar) {
            foreach ($orders as $order) {
                $providerIds = DB::connection('commerce')->table('order_items')
                    ->join('variants', 'order_items.variant_id', '=', 'variants.id')
                    ->join('products', 'variants.products_id', '=', 'products.id')
                    ->where('order_items.order_id', $order->id)
                    ->where('products.companies_id', '!=', $platformCompanyId)
                    ->pluck('products.companies_id')
                    ->unique();

                if ($providerIds->isEmpty()) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                foreach ($providerIds as $companyId) {
                    DB::connection('commerce')->table('order_providers')->insertOrIgnore([
                        'order_id' => $order->id,
                        'company_id' => $companyId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Backfill completed!");
        $this->info("Processed: {$processed} orders");
        $this->info("Skipped: {$skipped} orders (no provider companies)");

        return self::SUCCESS;
    }
}
