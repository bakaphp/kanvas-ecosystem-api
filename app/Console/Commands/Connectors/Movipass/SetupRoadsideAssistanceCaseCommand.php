<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;

class SetupRoadsideAssistanceCaseCommand extends Command
{
    protected $signature = 'kanvas:movipass-setup-roadside-assistance {app_id?}';

    protected $description = 'Setup Movipass roadside assistance order type and default status';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $app = $appId ? Apps::getById((int) $appId) : app(Apps::class);

        $orderType = OrderTypes::firstOrCreate([
            'apps_id' => $app->getId(),
            'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
        ], [
            'companies_id' => 0,
        ]);

        OrderStatus::firstOrCreate([
            'apps_id' => $app->getId(),
            'order_types_id' => $orderType->getId(),
            'slug' => MovipassOrderStatusEnum::REQUEST_SUBMITTED->value,
        ], [
            'name' => 'Request Submitted',
            'is_default' => true,
            'is_final' => false,
            'sequence' => 1,
        ]);

        $orderType->update([
            'total_statuses' => OrderStatus::where('order_types_id', $orderType->getId())
                ->where('is_deleted', 0)
                ->count(),
        ]);

        $this->info('Movipass roadside assistance setup completed successfully.');
    }
}
