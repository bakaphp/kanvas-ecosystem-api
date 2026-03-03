<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PasoRapido;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class CreatePasoRapidoOrderTransitionsCommand extends Command
{
    use KanvasJobsTrait;
    protected $signature = 'paso-rapido:create-order-transitions
                            {app_id : The app ID to process}
                            {--until= : Process orders created until this date (YYYY-MM-DD)}
                            {--dry-run : Run without making changes}';

    protected $description = 'Backfill order status transition history for existing paso_rapido paid orders';

    public function handle(): void
    {
        $isDryRun = (bool) $this->option('dry-run');
        $until = $this->option('until');
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->info("Processing paso_rapido order transitions for app: {$app->name} (ID: {$app->getId()})");

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        $query = Order::query()
            ->whereHas('orderType', fn ($q) => $q->where('name', 'paso_rapido'))
            ->where('apps_id', $app->getId())
            ->where('payment_status', PaymentStatusEnum::PAID->value)
            ->with(['orderType', 'payments']);

        if ($until) {
            try {
                $untilDate = Carbon::parse($until)->endOfDay();
                $query->where('created_at', '<=', $untilDate);
                $this->info("Filtering orders created until: {$untilDate->toDateString()}");
            } catch (Exception $e) {
                $this->error('Invalid date format for --until. Use YYYY-MM-DD.');
                return;
            }
        }

        $orders = $query->get();
        $total = $orders->count();

        if ($total === 0) {
            $this->warn('No paid paso_rapido orders found.');
            return;
        }

        $this->info("Found {$total} orders to process.");

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($orders as $order) {
            try {
                $result = $this->processOrder($order, $app->getId(), $isDryRun);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                    default   => null,
                };
            } catch (Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Order #{$order->order_number}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Status', 'Count'],
            [
                ['Created', $created],
                ['Updated', $updated],
                ['Skipped', $skipped],
                ['Errors', $errors],
                ['Total', $total],
            ]
        );

        if ($isDryRun) {
            $this->warn('Dry run complete. No changes were made.');
        }
    }

    protected function processOrder(Order $order, int $appId, bool $isDryRun): string
    {
        $orderType = $order->orderType;

        if (! $orderType) {
            throw new Exception("Order type not found for order #{$order->order_number}");
        }

        $draftStatus = $this->getStatus($orderType, 'draft');
        $paidStatus = $this->getStatus($orderType, 'paid');

        if (! $draftStatus || ! $paidStatus) {
            throw new Exception(
                "Required statuses not found for order type '{$orderType->name}'. Run paso-rapido:setup-order-status first."
            );
        }

        $existingCount = OrderTransitionHistory::where('order_id', $order->id)->count();
        $isUpdate = $existingCount > 0;

        if ($isDryRun) {
            return $isUpdate ? 'updated' : 'created';
        }

        $paidPayment = $order->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->orderBy('created_at', 'asc')
            ->first();

        $createdAt = $order->created_at;
        $paidAt = $paidPayment ? Carbon::parse($paidPayment->created_at) : Carbon::parse($order->updated_at);

        $statusTransition = OrderStatusTransitions::where([
            'order_types_id' => $orderType->id,
            'from_status_id' => $draftStatus->id,
            'to_status_id'   => $paidStatus->id,
        ])->first();

        DB::transaction(function () use ($order, $appId, $draftStatus, $paidStatus, $statusTransition, $createdAt, $paidAt, $isUpdate) {
            if ($isUpdate) {
                OrderTransitionHistory::where('order_id', $order->id)->delete();
            }

            OrderTransitionHistory::create([
                'apps_id'             => $appId,
                'companies_id'        => $order->companies_id ?? 0,
                'order_id'            => $order->id,
                'transition_id'       => null,
                'from_status_id'      => null,
                'to_status_id'        => $draftStatus->id,
                'description'         => 'Order created as draft',
                'metadata'            => json_encode($order->metadata ?? []),
                'is_current'          => false,
                'created_at'          => $createdAt,
                'changed_at'          => $createdAt,
                'ended_at'            => $paidAt,
                'duration_in_seconds' => $createdAt->diffInSeconds($paidAt),
                'changed_by'          => $order->users_id,
                'ended_by'            => $order->users_id,
            ]);

            OrderTransitionHistory::create([
                'apps_id'        => $appId,
                'companies_id'   => $order->companies_id ?? 0,
                'order_id'       => $order->id,
                'transition_id'  => $statusTransition?->id,
                'from_status_id' => $draftStatus->id,
                'to_status_id'   => $paidStatus->id,
                'description'    => 'Order paid',
                'metadata'       => json_encode($order->metadata ?? []),
                'is_current'     => true,
                'created_at'     => $paidAt,
                'changed_at'     => $paidAt,
                'changed_by'     => $order->users_id,
            ]);

            $order->updateQuietly(['order_status_id' => $paidStatus->id]);
        });

        return $isUpdate ? 'updated' : 'created';
    }

    protected function getStatus(OrderTypes $orderType, string $slug): ?OrderStatus
    {
        return OrderStatus::where([
            'order_types_id' => $orderType->id,
            'slug'           => $slug,
        ])->first();
    }
}
