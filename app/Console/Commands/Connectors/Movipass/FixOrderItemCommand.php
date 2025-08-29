<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order;

class FixOrderItemCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:movipass-fix-order-items 
                            {app_id : The application ID}
                            {--start-date= : Start date (Y-m-d format)}
                            {--end-date= : End date (Y-m-d format)}
                            {--order-ids= : Comma-separated list of specific order IDs to update}
                            {--substitute-item= : Substitute specific variant ID (format: "old_id,new_id")}
                            {--timezone= : Timezone for date range (defaults to app timezone or UTC)}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Fix order items for Movipass orders within a date range';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $orderIds = $this->option('order-ids');
        $substituteItem = $this->option('substitute-item');
        $timezone = $this->option('timezone');
        $isDryRun = $this->option('dry-run');

        // Validate required parameters - either date range OR order IDs
        if (! $orderIds && (! $startDate || ! $endDate)) {
            $this->error('Either --order-ids OR both --start-date and --end-date options are required');
            return;
        }

        // Only validate dates if not using order IDs
        if (! $orderIds) {
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
        }

        $app = Apps::getById($appId);
        $this->overwriteAppService($app);

        // Parse substitute item option
        $substitution = null;
        if ($substituteItem) {
            $parts = array_map('trim', explode(',', $substituteItem));
            if (count($parts) !== 2) {
                $this->error('Substitute item format must be "old_id,new_id"');
                return;
            }
            $substitution = ['old' => (int) $parts[0], 'new' => (int) $parts[1]];
            $this->info("Will substitute variant {$substitution['old']} with variant {$substitution['new']}");
        }

        if ($orderIds) {
            // Process specific order IDs
            $orderIdsArray = array_map('trim', explode(',', $orderIds));
            $this->info("Processing specific orders: " . implode(', ', $orderIdsArray));

            if ($isDryRun) {
                $this->warn('DRY RUN MODE - No changes will be made');
            }

            $this->updateOrderItemsByIds($app, $orderIdsArray, $substitution, $isDryRun);
        } else {
            // Process by date range
            $appTimeZone = $timezone ?? $app->get('timezone') ?? 'UTC';

            $startDate = $startDate->setTimezone($appTimeZone);
            $endDate = $endDate->setTimezone($appTimeZone);

            $this->info("Date Range Details:");
            $this->info("  Timezone: {$appTimeZone}  Start Date: {$startDate->toDateTimeString()}  End Date: {$endDate->toDateTimeString()}");

            if ($isDryRun) {
                $this->warn('DRY RUN MODE - No changes will be made');
            }

            $this->updateOrderItems($app, $startDate, $endDate, $substitution, $isDryRun);
        }
    }

    protected function updateOrderItems(Apps $app, Carbon $startDate, Carbon $endDate, ?array $substitution, bool $isDryRun): void
    {
        $orders = Order::whereBetween('created_at', [$startDate, $endDate])
            ->fromApp($app)
            ->with(['items.variant.product'])
            ->get();

        $this->info("Found {$orders->count()} orders in the specified date range");

        $updatedCount = 0;
        $progressBar = $this->output->createProgressBar($orders->count());

        foreach ($orders as $order) {
            $wasUpdated = $this->processOrder($order, $substitution, $isDryRun);

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

    protected function updateOrderItemsByIds(Apps $app, array $orderIds, array $substitution, bool $isDryRun): void
    {
        $orders = Order::whereIn('id', $orderIds)
            ->fromApp($app)
            ->with(['items.variant.product'])
            ->get();

        $this->info("Found {$orders->count()} orders by ID");

        $updatedCount = 0;
        $progressBar = $this->output->createProgressBar($orders->count());

        foreach ($orders as $order) {
            $wasUpdated = $this->processOrder($order, $substitution, $isDryRun);

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

    protected function processOrder(Order $order, array $substitution, bool $isDryRun): bool
    {
        $oldVariantId = $substitution['old'];
        $newVariantId = $substitution['new'];

        // Find items with the old variant ID
        $itemsToReplace = $order->items()->where('variant_id', $oldVariantId)->get();

        if ($itemsToReplace->isEmpty()) {
            return false; // Nothing to substitute
        }

        if ($isDryRun) {
            $this->line("Would substitute {$itemsToReplace->count()} items in order {$order->id}: variant {$oldVariantId} → {$newVariantId}");
            return true;
        }

        $newVariant = ModelsVariants::find($newVariantId);
        if (! $newVariant) {
            $this->error("New variant {$newVariantId} not found");
            return false;
        }

        try {
            $price = $newVariant?->getPriceInfoFromDefaultChannel()?->price ?? 0;
        } catch (Exception) {
            $price = 0;
        }

        foreach ($itemsToReplace as $item) {
            $item->delete();
        }

        $orderItem = OrderItem::viaRequest($order->app, $order->company, $order->region, [
            'variant_id' => $newVariant->id,
            'quantity' => 1, // Default quantity, adjust as needed
            'price' => $price, // Will be calculated from variant price
        ]);

        $order->addItem($orderItem);

        $order->metadata = [
            "data" => [
                ...($order->metadata["data"] ?? []),
                "updated_by_system_at" => now()->toDateTimeString(),
            ]
        ];

        $order->calculateTotal(false);

        // Save without updating the updated_at timestamp
        $originalUpdatedAt = $order->updated_at;
        $order->timestamps = false;
        $order->updated_at = $originalUpdatedAt;
        $order->saveQuietly();
        $order->timestamps = true;

        return true;
    }
}
