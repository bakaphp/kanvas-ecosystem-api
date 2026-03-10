<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PasoRapido;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

class SetupPasoRapidoOrderStatusCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'paso-rapido:setup-order-status {app_id : The app ID to setup statuses for}';

    protected $description = 'Create paso_rapido order type, statuses (draft, paid) and transitions';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->info("Setting up paso_rapido order statuses for app: {$app->name} (ID: {$app->getId()})");

        $orderType = OrderTypes::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'name' => 'paso_rapido',
            ],
            [
                'companies_id' => 0,
                'slug' => 'paso_rapido',
            ]
        );

        $this->info($orderType->wasRecentlyCreated ? 'OrderType created.' : 'OrderType already exists.');

        $createdStatus = OrderStatus::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'order_types_id' => $orderType->id,
                'slug' => 'draft',
            ],
            [
                'companies_id' => 0,
                'name' => 'Draft',
                'slug' => Str::slug('draft'),
                'is_default' => true,
                'is_final' => false,
                'sequence' => 1,
            ]
        );

        $paidStatus = OrderStatus::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'order_types_id' => $orderType->id,
                'slug' => 'paid',
            ],
            [
                'companies_id' => 0,
                'name' => 'Paid',
                'slug' => Str::slug('paid'),
                'is_default' => false,
                'is_final' => true,
                'sequence' => 2,
            ]
        );

        $this->info('Statuses created: draft (default), paid (final).');

        OrderStatusTransitions::firstOrCreate(
            [
                'order_types_id' => $orderType->id,
                'from_status_id' => $createdStatus->id,
                'to_status_id' => $paidStatus->id,
            ],
            [
                'name' => 'created_to_paid',
            ]
        );

        $this->info('Transition created: draft → paid.');
        $this->info('Setup complete.');
    }
}
