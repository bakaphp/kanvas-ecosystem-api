<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\MarkOrderAsDuplicateAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Throwable;

class ScanDuplicateOrdersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'movipass:scan-duplicate-orders
                            {app_id? : App ID to scan (defaults to current app)}
                            {--order_type_id= : Scan a specific order type only}';

    protected $description = 'Detect and flag duplicate impound orders based on order type config';

    public function handle(): void
    {
        $app = $this->argument('app_id')
            ? Apps::getById((int) $this->argument('app_id'))
            : app(Apps::class);

        $this->overwriteAppService($app);

        $query = OrderTypes::fromApp($app)->notDeleted();

        if ($orderTypeId = $this->option('order_type_id')) {
            $query->where('id', (int) $orderTypeId);
        }

        $orderTypes = $query->get()->filter(
            fn (OrderTypes $ot) => ! empty($ot->config['validate_metadata_duplicated_field'])
        );

        if ($orderTypes->isEmpty()) {
            $this->info('No order types with duplicate detection config found.');

            return;
        }

        $systemUser = Users::find(1);

        foreach ($orderTypes as $orderType) {
            $this->scanOrderType($app, $orderType, $systemUser);
        }
    }

    private function scanOrderType(Apps $app, OrderTypes $orderType, Users $systemUser): void
    {
        $config = $orderType->config;
        $metadataField = $config['validate_metadata_duplicated_field']; // e.g. "data.vehiclePlate"
        $excludeSlugs = array_map('trim', explode(',', $config['validate_metadata_duplicated_exclude_statuses'] ?? ''));

        $this->info("Scanning order type: {$orderType->name} (field: {$metadataField})");

        $orders = Order::query()
            ->where('order_types_id', $orderType->id)
            ->fromApp($app)
            ->notDeleted()
            ->with('orderStatus')
            ->get()
            ->filter(function (Order $order) use ($excludeSlugs) {
                $slug = $order->orderStatus?->slug ?? '';

                return ! in_array($slug, $excludeSlugs, true);
            });

        // Group by the configured metadata field value; skip orders where the field is empty
        $grouped = $orders->groupBy(function (Order $order) use ($metadataField) {
            return data_get($order->metadata, $metadataField);
        })->filter(fn ($group, $key) => ! empty($key) && $group->count() > 1);

        $flagged = 0;

        foreach ($grouped as $fieldValue => $duplicates) {
            // Oldest order is the original; flag the rest
            $sorted = $duplicates->sortBy('created_at');
            $original = $sorted->shift();

            foreach ($sorted as $duplicate) {
                // Skip if already marked as a duplicate
                if (! empty($duplicate->metadata['data']['duplicate_of_order_id'])) {
                    continue;
                }

                try {
                    Order::withoutSyncingToSearch(
                        fn () => new MarkOrderAsDuplicateAction(
                            $duplicate,
                            $systemUser,
                            $original,
                            "Auto-detected: same {$metadataField} ({$fieldValue}) as order #{$original->order_number}",
                        )->execute()
                    );

                    $flagged++;
                    $this->line("  Flagged order #{$duplicate->order_number} as duplicate of #{$original->order_number}");
                } catch (ValidationException $e) {
                    $this->warn("  Skipped order #{$duplicate->order_number}: {$e->getMessage()}");
                } catch (Throwable $e) {
                    $this->error("  Error flagging order #{$duplicate->order_number}: {$e->getMessage()}");
                }
            }
        }

        $this->info("  Done. {$flagged} order(s) flagged.");
    }
}
