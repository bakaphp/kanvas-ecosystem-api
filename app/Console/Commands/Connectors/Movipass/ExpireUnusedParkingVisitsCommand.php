<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Parking\Actions\ExpireUnusedParkingVisitsAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class ExpireUnusedParkingVisitsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:expire-unused-parking-visits {app_id?}';

    protected $description = 'Cancel unscanned walk-in parking visits, complete stuck exits and re-dispatch unconfirmed parking payments';

    public function handle(): int
    {
        $failed = 0;

        foreach ($this->appIds() as $appId) {
            try {
                $failed += $this->processApp($appId);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('App %d failed: %s', $appId, $e->getMessage()));
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processApp(int $appId): int
    {
        $app = Apps::getById($appId);
        $this->overwriteAppService($app);

        $report = new ExpireUnusedParkingVisitsAction($app)->execute();

        $this->info(sprintf(
            'App %d: expired=%d exits_completed=%d confirmations_attempted=%d failed=%d',
            $appId,
            $report['expired'],
            $report['exits_completed'],
            $report['confirmations_attempted'],
            $report['failed']
        ));

        return $report['failed'];
    }

    private function appIds(): array
    {
        if ($this->argument('app_id') !== null) {
            return [(int) $this->argument('app_id')];
        }

        return Order::query()
            ->where('resources_type', new Event()->getMorphClass())
            ->notDeleted()
            ->whereNotIn('status', OrderStatusEnum::closedValues())
            ->whereHas(
                'orderStatus',
                fn ($status) => $status->whereIn('slug', [MovipassOrderStatusEnum::CREATED->slug(), MovipassOrderStatusEnum::ACTIVE->slug()])
            )
            ->distinct()
            ->pluck('apps_id')
            ->all();
    }
}
