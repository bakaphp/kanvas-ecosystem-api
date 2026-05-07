<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

/**
 * Migrates existing movipass orders to have proper status transition history.
 */
class CreateMovipassOrderTransitionsCommand extends Command
{
    protected $signature = 'movipass:create-order-transitions
                            {--app_id= : Filter by specific app ID}
                            {--until= : Process orders created until this date (YYYY-MM-DD)}
                            {--dry-run : Run without making changes}';

    protected $description = 'Create order status transitions for all existing movipass orders';

    public function handle(): void
    {
        $isDryRun = $this->option('dry-run');
        $appId = $this->option('app_id');
        $until = $this->option('until');

        $this->info('Starting movipass order transitions migration...');
        $this->newLine();

        $query = Order::query()
            ->whereHas('orderType', function ($q) {
                $q->where('name', 'movipass');
            })
            ->with(['orderType', 'payments']);

        if ($appId) {
            $query->where('apps_id', $appId);
            $this->info("Filtering orders for app_id: {$appId}");
        }

        if ($until) {
            try {
                $untilDate = Carbon::parse($until)->endOfDay();
                $query->where('created_at', '<=', $untilDate);
                $this->info("Filtering orders created until: {$untilDate->toDateString()}");
            } catch (\Exception $e) {
                $this->error("Invalid date format for --until option. Use YYYY-MM-DD format.");
                return;
            }
        }

        $orders = $query->get();
        $totalOrders = $orders->count();

        if ($totalOrders === 0) {
            $this->warn('No movipass orders found.');
            return;
        }

        $this->info("Found {$totalOrders} movipass orders to process.");
        $this->newLine();

        $processedCount = 0;
        $updatedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($totalOrders);
        $progressBar->start();

        foreach ($orders as $order) {
            try {
                $result = $this->processOrder($order, $isDryRun);
                if ($result === 'created') {
                    $processedCount++;
                } elseif ($result === 'updated') {
                    $updatedCount++;
                }
            } catch (Exception $e) {
                $errorCount++;
                $this->newLine();
                $this->error("Error processing order #{$order->order_number}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Migration completed!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Created', $processedCount],
                ['Updated', $updatedCount],
                ['Errors', $errorCount],
                ['Total', $totalOrders],
            ]
        );

        if ($isDryRun) {
            $this->warn('This was a dry run. No changes were made to the database.');
        }
    }

    protected function processOrder(Order $order, bool $isDryRun): string
    {
        $orderType = $order->orderType;
        if (! $orderType) {
            throw new Exception("Order type not found for order #{$order->order_number}");
        }

        $transitions = $this->buildTransitions($order, $orderType);

        if (empty($transitions)) {
            throw new Exception("No transitions to create for order #{$order->order_number}");
        }

        $existingTransitions = OrderTransitionHistory::where('order_id', $order->id)->count();
        $isUpdate = $existingTransitions > 0;

        if ($isDryRun) {
            return $isUpdate ? 'updated' : 'created';
        }

        DB::transaction(function () use ($order, $orderType, $transitions, $isUpdate) {
            if ($isUpdate) {
                OrderTransitionHistory::where('order_id', $order->id)->delete();
            }

            $lastTransitionIndex = count($transitions) - 1;

            foreach ($transitions as $index => $transitionData) {
                $isCurrent = $index === $lastTransitionIndex;

                $fromStatusId = $transitionData['from_status_id'];
                $toStatusId = $transitionData['to_status_id'];
                $createdAt = $transitionData['created_at'];
                $changedAt = $transitionData['changed_at'];
                $changedBy = $transitionData['changed_by'];

                $statusTransition = null;
                if ($fromStatusId && $toStatusId) {
                    $statusTransition = OrderStatusTransitions::where([
                        'order_types_id' => $orderType->id,
                        'from_status_id' => $fromStatusId,
                        'to_status_id' => $toStatusId,
                    ])->first();
                }

                $historyData = [
                    'apps_id' => $order->apps_id,
                    'companies_id' => $order->companies_id,
                    'order_id' => $order->id,
                    'transition_id' => $statusTransition?->id,
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $toStatusId,
                    'description' => $transitionData['description'],
                    'metadata' => json_encode($order->metadata ?? []),
                    'is_current' => $isCurrent,
                    'created_at' => $createdAt,
                    'changed_at' => $changedAt,
                    'changed_by' => $changedBy,
                ];

                if (isset($transitionData['ended_at'])) {
                    $historyData['ended_at'] = $transitionData['ended_at'];
                    $historyData['ended_by'] = $changedBy;
                    $historyData['duration_in_seconds'] = Carbon::parse($changedAt)
                        ->diffInSeconds(Carbon::parse($transitionData['ended_at']));
                }

                OrderTransitionHistory::create($historyData);
            }

            if (! empty($transitions)) {
                $lastTransition = end($transitions);
                $order->updateQuietly([
                    'order_status_id' => $lastTransition['to_status_id'],
                ]);
            }
        });

        return $isUpdate ? 'updated' : 'created';
    }

    protected function buildTransitions(Order $order, OrderTypes $orderType): array
    {
        $transitions = [];

        $createdStatus = $this->getStatus($orderType, 'created');
        $paidStatus = $this->getStatus($orderType, 'paid');
        $completedStatus = $this->getStatus($orderType, 'completed');

        if (! $createdStatus || ! $paidStatus || ! $completedStatus) {
            echo "Required statuses not found for order type '{$orderType->name}'\n";
            return [];
        }

        $createdAt = $order->created_at;
        $paidPayment = $this->getPaidPayment($order);
        $completedAt = $this->getCompletedDate($order);

        $transitions[] = [
            'from_status_id' => null,
            'to_status_id' => $createdStatus->id,
            'created_at' => $createdAt,
            'changed_at' => $createdAt,
            'changed_by' => $order->users_id,
            'description' => 'Order created',
        ];

        if ($paidPayment) {
            $paidAt = Carbon::parse($paidPayment->created_at);
            $transitions[0]['ended_at'] = $paidAt;

            $transitions[] = [
                'from_status_id' => $createdStatus->id,
                'to_status_id' => $paidStatus->id,
                'created_at' => $paidAt,
                'changed_at' => $paidAt,
                'changed_by' => $paidPayment->users_id,
                'description' => 'Order paid',
            ];
        }

        if ($completedAt) {
            if ($paidPayment) {
                $transitions[1]['ended_at'] = $completedAt;
            } else {
                $transitions[0]['ended_at'] = $completedAt;
            }

            $fromStatusId = $paidPayment ? $paidStatus->id : $createdStatus->id;

            $transitions[] = [
                'from_status_id' => $fromStatusId,
                'to_status_id' => $completedStatus->id,
                'created_at' => $completedAt,
                'changed_at' => $completedAt,
                'changed_by' => $order->users_id,
                'description' => 'Order completed',
            ];
        }

        return $transitions;
    }

    protected function getStatus(OrderTypes $orderType, string $statusSlug): ?OrderStatus
    {
        return OrderStatus::where([
            'order_types_id' => $orderType->id,
            'slug' => $statusSlug,
        ])->first();
    }

    protected function getPaidPayment(Order $order)
    {
        return $order->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->orderBy('created_at', 'asc')
            ->first();
    }

    protected function getCompletedDate(Order $order): ?Carbon
    {
        $metadata = $order->metadata;

        if (! $metadata || ! is_array($metadata)) {
            return null;
        }

        $endedAt = $metadata['data']['end_at'] ?? $metadata['end_at'] ?? null;

        if (! $endedAt) {
            return null;
        }

        try {
            return Carbon::parse($endedAt);
        } catch (Exception $e) {
            return null;
        }
    }
}
