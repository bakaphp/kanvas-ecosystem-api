<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

class SetupImpoundLotCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'movipass:setup-impound-lot {app_id?}';

    protected $description = 'Setup impound_lot order type, statuses, and transitions (idempotent)';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        /** @var Apps $app */
        $app = $appId ? Apps::getById((int) $appId) : app(Apps::class);

        $this->overwriteAppService($app);

        $orderType = OrderTypes::firstOrCreate(
            ['apps_id' => $app->getId(), 'name' => OrderTypeEnum::IMPOUND_LOT->value],
            ['is_default' => false, 'companies_id' => 0]
        );

        $statusDefs = [
            MovipassOrderStatusEnum::IN_TRANSIT->value => ['name' => 'En Traslado', 'is_default' => true, 'is_final' => false, 'sequence' => 1],
            MovipassOrderStatusEnum::AWAITING_DELIVERY_CONFIRMATION->value => ['name' => 'Validar Traslado', 'is_default' => false, 'is_final' => false, 'sequence' => 2],
            MovipassOrderStatusEnum::DELIVERED->value => ['name' => 'Depositado', 'is_default' => false, 'is_final' => false, 'sequence' => 3],
            MovipassOrderStatusEnum::PAID->value => ['name' => 'Pago Completado', 'is_default' => false, 'is_final' => false, 'sequence' => 4],
            MovipassOrderStatusEnum::RELEASED->value => ['name' => 'Despachado', 'is_default' => false, 'is_final' => true, 'sequence' => 5],
            MovipassOrderStatusEnum::CANCELLED->value => ['name' => 'Traslado Cancelado', 'is_default' => false, 'is_final' => true, 'sequence' => 6],
            MovipassOrderStatusEnum::TRIAL_PHASE->value => ['name' => 'Plan Piloto', 'is_default' => false, 'is_final' => false, 'sequence' => 7],
        ];

        $statuses = [];
        OrderStatus::withoutSyncingToSearch(function () use (&$statuses, $app, $orderType, $statusDefs) {
            foreach ($statusDefs as $slug => $attrs) {
                $statuses[$slug] = OrderStatus::firstOrCreate(
                    ['apps_id' => $app->getId(), 'order_types_id' => $orderType->id, 'slug' => $slug],
                    [...$attrs, 'companies_id' => 0]
                );
            }
        });

        $inTransit = MovipassOrderStatusEnum::IN_TRANSIT->value;
        $awaitingDelivery = MovipassOrderStatusEnum::AWAITING_DELIVERY_CONFIRMATION->value;
        $delivered = MovipassOrderStatusEnum::DELIVERED->value;
        $paid = MovipassOrderStatusEnum::PAID->value;
        $released = MovipassOrderStatusEnum::RELEASED->value;
        $cancelled = MovipassOrderStatusEnum::CANCELLED->value;
        $trialPhase = MovipassOrderStatusEnum::TRIAL_PHASE->value;

        $transitionPairs = [
            [$inTransit, $awaitingDelivery],
            [$inTransit, $cancelled],
            [$awaitingDelivery, $delivered],
            [$awaitingDelivery, $cancelled],
            [$delivered, $paid],
            [$delivered, $cancelled],
            [$delivered, $trialPhase],
            [$delivered, $awaitingDelivery],
            [$trialPhase, $paid],
            [$paid, $released],
        ];

        $rows = [];
        foreach ($transitionPairs as [$fromSlug, $toSlug]) {
            $from = $statuses[$fromSlug];
            $to = $statuses[$toSlug];

            $record = OrderStatusTransitions::firstOrCreate(
                ['order_types_id' => $orderType->id, 'from_status_id' => $from->id, 'to_status_id' => $to->id],
                ['name' => "{$from->name} → {$to->name}", 'companies_id' => 0]
            );

            $rows[] = [
                "{$fromSlug} → {$toSlug}",
                $record->wasRecentlyCreated ? 'created' : 'existed',
            ];
        }

        $this->info("Order type: {$orderType->name} (id: {$orderType->id})");
        $this->table(['Transition', 'Result'], $rows);
    }
}
