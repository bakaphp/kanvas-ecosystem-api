<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\DataTransferObject\Variants;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order;

class UpdateOrderItemsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:movipass-update-order-items 
                            {app_id : The application ID}
                            {variant_id : variant ids of the items to be added}
                            {--start-date= : Start date (Y-m-d format)}
                            {--end-date= : End date (Y-m-d format)}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Update order items for Movipass orders within a date range';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $variantIds = $this->argument('variant_id');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $isDryRun = $this->option('dry-run');

        // Validate required parameters
        if (!$startDate || !$endDate) {
            $this->error('Both --start-date and --end-date options are required');
            return;
        }

        try {
            $startDate = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            $endDate = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid date format. Please use Y-m-d format (e.g., 2024-01-15)');
            return;
        }

        if ($startDate->gt($endDate)) {
            $this->error('Start date must be before or equal to end date');
            return;
        }

        $app = Apps::getById($appId);
        $this->overwriteAppService($app);

        $this->info("Processing orders from {$startDate->toDateString()} to {$endDate->toDateString()}");
        
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $variant =  Variants::find();
        $this->updateOrderItems($app, $variant, $startDate, $endDate, $isDryRun);
    }

    protected function updateOrderItems(Apps $app, Apps $variant, Carbon $startDate, Carbon $endDate, bool $isDryRun): void
    {
        $orders = Order::whereBetween('created_at', [$startDate, $endDate])
            ->with(['items.variant.product'])
            ->get();

        $this->info("Found {$orders->count()} orders in the specified date range");

        $updatedCount = 0;
        $progressBar = $this->output->createProgressBar($orders->count());
       

        foreach ($orders as $order) {
            $wasUpdated = $this->processOrder($order, $isDryRun);
            
            if ($wasUpdated) {
                $updatedCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($isDryRun) {
            $this->info("DRY RUN: Would update {$updatedCount} orders");
        } else {
            $this->info("Successfully updated {$updatedCount} orders");
        }
    }

    protected function processOrder(Order $order, bool $isDryRun): bool
    {
        $wasUpdated = false;
        if ($isDryRun && $wasUpdated) {
            foreach ($order->items as $item) {
                $this->line("Would update order {$order->id}, item {$item->id}");
            }
        } else {
            $order->deleteItems();
            $orderItem = OrderItem::viaRequest($order->app, $order->company, $order->region, [
                'variant_id' => $lateFee->id,
                'quantity' => $feeCount,
                'price' => $lateFeePrice,
            ]);

            $order->addItem($orderItem);
            
            $order->metadata = [
                "data" => [
                    ...$order->metadata["data"],
                    "updated_by_system_at" => $timeZonedNow,
                ]
            ];
            $order->calculateTotal(false);
    
            // Save without updating the updated_at timestamp
            $originalUpdatedAt = $order->updated_at;
            $order->timestamps = false;
            $order->updated_at = $originalUpdatedAt;
            $order->saveQuietly();
            $order->timestamps = true;
            $wasUpdated = true;
            
        }

        return $wasUpdated;
    }
}