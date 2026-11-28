<?php

declare(strict_types=1);

namespace App\Console\Commands\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\AssignImpoundOrderToPayerAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Throwable;

class BackfillImpoundPayerCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:backfill-impound-payer
        {app_id : The app the retentions belong to}
        {--dry-run : List the retentions that would move without changing them}
        {--limit= : Stop after this many retentions are moved (or listed)}';

    protected $description = 'Move paid vehicle retentions (impound_lot) still owned by the operator to the user of their latest paid payment';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $counts = ['checked' => 0, 'moved' => 0, 'skipped' => 0, 'failed' => 0];

        Order::query()
            ->fromApp($app)
            ->notDeleted()
            ->whereHas('orderType', fn ($query) => $query->where('name', OrderTypeEnum::IMPOUND_LOT->value))
            ->whereHas('payments', fn ($query) => $query->where('status', PaymentStatusEnum::PAID->value))
            ->chunkById(500, function ($orders) use ($dryRun, $limit, &$counts) {
                foreach ($orders as $order) {
                    if ($limit !== null && $counts['moved'] >= $limit) {
                        return false;
                    }

                    $counts['checked']++;

                    try {
                        $action = new AssignImpoundOrderToPayerAction($order);

                        if (! $action->needsMove()) {
                            $counts['skipped']++;

                            continue;
                        }

                        if ($dryRun) {
                            $this->line("Order {$order->getId()}: user {$order->users_id} -> {$action->payer()->getId()}");
                            $counts['moved']++;

                            continue;
                        }

                        $action->execute() === null ? $counts['skipped']++ : $counts['moved']++;
                    } catch (Throwable $e) {
                        report($e);
                        $counts['failed']++;
                        $this->error("Order {$order->getId()} failed: {$e->getMessage()}");
                    }
                }
            });

        $this->info(sprintf(
            '%s %d retention(s) checked: %d %s, %d skipped, %d failed',
            $dryRun ? '[dry-run]' : '',
            $counts['checked'],
            $counts['moved'],
            $dryRun ? 'would move' : 'moved',
            $counts['skipped'],
            $counts['failed'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
