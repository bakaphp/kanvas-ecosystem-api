<?php

declare(strict_types=1);

namespace App\Console\Commands\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class BackfillUnpaidOrdersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:backfill-unpaid-orders
        {app_id : The app ID the orders belong to}
        {--since= : Only fix payments created at or after this datetime (required, e.g. "2026-04-24 16:13:11")}
        {--order-types= : Comma-separated order type names to scope to (default: movipass,paso_rapido,impound_lot,roadside_assistance)}
        {--limit= : Maximum number of orders to process}
        {--dry-run : Show what would be marked paid without making changes}';

    protected $description = 'Backfill orders with a PAID payment that were never marked paid (regression from feat/payment-improvements: removed inline Order::markAsPaid call relied on a broken Payment::markAsPaid cascade).';

    public function handle(): int
    {
        $sinceArg = $this->option('since');
        if (empty($sinceArg)) {
            $this->error('--since is required (datetime lower bound, e.g. "2026-04-24 16:13:11").');

            return self::INVALID;
        }

        try {
            $since = Carbon::parse((string) $sinceArg);
        } catch (Throwable $e) {
            $this->error("Invalid --since datetime: {$sinceArg}");

            return self::INVALID;
        }

        $orderTypesArg = $this->option('order-types');
        $orderTypeNames = empty($orderTypesArg)
            ? array_map(fn (OrderTypeEnum $t) => $t->value, OrderTypeEnum::cases())
            : array_values(array_filter(array_map('trim', explode(',', (string) $orderTypesArg))));

        if (empty($orderTypeNames)) {
            $this->error('--order-types must contain at least one type name.');

            return self::INVALID;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $baseQuery = Payments::query()
            ->where('apps_id', $app->getId())
            ->where('status', PaymentStatusEnum::PAID->value)
            ->where('payable_type', Order::class)
            ->where('created_at', '>=', $since)
            ->whereHas(
                'order',
                fn ($q) => $q->where('payment_status', '!=', PaymentStatusEnum::PAID->value)
                    ->notDeleted()
                    ->whereHas(
                        'orderType',
                        fn ($oq) => $oq->whereIn('name', $orderTypeNames),
                    ),
            )
            ->orderBy('id');

        if ($limit !== null) {
            $baseQuery->limit($limit);
        }

        $count = (clone $baseQuery)->count();
        if ($count === 0) {
            info('No orders matched the filters. Nothing to do.');

            return self::SUCCESS;
        }

        $sample = (clone $baseQuery)
            ->with(['order:id,uuid,payment_status,total,created_at'])
            ->limit(10)
            ->get()
            ->map(fn (Payments $p) => [
                'payment_id' => $p->id,
                'order_id' => $p->order?->id,
                'order_uuid' => $p->order?->uuid,
                'order_payment_status' => $p->order?->payment_status,
                'payment_amount' => $p->amount,
                'payment_created_at' => (string) $p->created_at,
            ])
            ->toArray();

        table(
            ['payment_id', 'order_id', 'order_uuid', 'order_payment_status', 'payment_amount', 'payment_created_at'],
            $sample,
        );

        info("Matched {$count} payment(s) with PAID status whose order is still unpaid — showing first 10 above.");
        info(sprintf(
            'App: %s (ID %d) — since: %s — order types: %s%s',
            $app->name,
            $app->getId(),
            $since->toIso8601String(),
            implode(',', $orderTypeNames),
            $limit !== null ? " — limit: {$limit}" : '',
        ));

        if ($this->option('dry-run')) {
            info('Dry run — no changes made.');

            return self::SUCCESS;
        }

        if (! confirm("Proceed with marking {$count} order(s) as paid?", default: false)) {
            info('Aborted.');

            return self::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        $baseQuery->with(['order', 'user'])->cursor()->each(function (Payments $payment) use (&$processed, &$skipped, &$failed): void {
            $order = $payment->order;
            if ($order === null || $order->payment_status === PaymentStatusEnum::PAID->value) {
                $skipped++;

                return;
            }

            if ($payment->user === null) {
                $this->warn("Skipping payment {$payment->id}: missing user.");
                $skipped++;

                return;
            }

            try {
                $before = $order->payment_status;
                $order->markAsPaid($payment->user);
                $processed++;
                $this->line(sprintf(
                    'Order %d: %s → %s (via payment %d)',
                    $order->id,
                    $before ?? 'null',
                    PaymentStatusEnum::PAID->value,
                    $payment->id,
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed to mark order {$order->id} paid (payment {$payment->id}): {$e->getMessage()}");
                report($e);
            }
        });

        info("Done. Processed: {$processed}. Skipped: {$skipped}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
