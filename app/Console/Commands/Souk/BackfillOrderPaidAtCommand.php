<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class BackfillOrderPaidAtCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-souk:backfill-order-paid-at {app_id} {--chunk=5000} {--dry-run}';

    protected $description = 'Backfill orders.paid_at for paid orders of an app: first transition into paid, else first paid payment, else created_at';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $chunk = max((int) $this->option('chunk'), 1);
        $pending = $this->pendingOrders($app);
        $total = (clone $pending)->count();

        $this->info("App {$app->name}: {$total} paid orders without paid_at.");

        if ($total === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        $minId = (int) (clone $pending)->min('id');
        $maxId = (int) (clone $pending)->max('id');
        $filled = ['transition' => 0, 'payment' => 0, 'created_at' => 0];

        for ($from = $minId; $from <= $maxId; $from += $chunk) {
            $to = $from + $chunk - 1;

            $filled['transition'] += $this->fillFromPaidTransition($app, $from, $to);
            $filled['payment'] += $this->fillFromFirstPayment($app, $from, $to);
            $filled['created_at'] += $this->fillFromCreatedAt($app, $from, $to);

            $this->line("Orders {$from}-{$to} done.");
        }

        $this->info(sprintf(
            'Done. From transition: %d, from payment: %d, from created_at: %d.',
            $filled['transition'],
            $filled['payment'],
            $filled['created_at'],
        ));

        return self::SUCCESS;
    }

    private function pendingOrders(Apps $app): Builder
    {
        return DB::connection('commerce')
            ->table('orders')
            ->where('apps_id', $app->getId())
            ->where('payment_status', PaymentStatusEnum::PAID->value)
            ->whereNull('paid_at');
    }

    private function fillFromPaidTransition(Apps $app, int $from, int $to): int
    {
        return DB::connection('commerce')->update(
            <<<'SQL'
                UPDATE orders o
                JOIN (
                    SELECT oth.order_id, MIN(oth.changed_at) AS changed_at
                    FROM order_transitions_history oth
                    INNER JOIN order_statuses os ON os.id = oth.to_status_id
                    WHERE os.slug = ? AND oth.order_id BETWEEN ? AND ?
                    GROUP BY oth.order_id
                ) t ON t.order_id = o.id
                SET o.paid_at = t.changed_at
                WHERE o.apps_id = ? AND o.id BETWEEN ? AND ? AND o.payment_status = ? AND o.paid_at IS NULL
            SQL,
            [
                PaymentStatusEnum::PAID->value,
                $from,
                $to,
                $app->getId(),
                $from,
                $to,
                PaymentStatusEnum::PAID->value,
            ]
        );
    }

    private function fillFromFirstPayment(Apps $app, int $from, int $to): int
    {
        return DB::connection('commerce')->update(
            <<<'SQL'
                UPDATE orders o
                JOIN (
                    SELECT payable_id AS order_id, MIN(payment_date) AS payment_date
                    FROM payments
                    WHERE payable_type = ?
                      AND is_deleted = 0
                      AND status = ?
                      AND payment_date IS NOT NULL
                      AND payable_id BETWEEN ? AND ?
                    GROUP BY payable_id
                ) p ON p.order_id = o.id
                SET o.paid_at = p.payment_date
                WHERE o.apps_id = ? AND o.id BETWEEN ? AND ? AND o.payment_status = ? AND o.paid_at IS NULL
            SQL,
            [
                Order::class,
                PaymentStatusEnum::PAID->value,
                $from,
                $to,
                $app->getId(),
                $from,
                $to,
                PaymentStatusEnum::PAID->value,
            ]
        );
    }

    private function fillFromCreatedAt(Apps $app, int $from, int $to): int
    {
        return $this->pendingOrders($app)
            ->whereBetween('id', [$from, $to])
            ->update(['paid_at' => DB::raw('created_at')]);
    }
}
