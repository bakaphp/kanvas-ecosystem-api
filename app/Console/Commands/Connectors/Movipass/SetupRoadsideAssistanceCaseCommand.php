<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\CreateOrderStatusesAction;

class SetupRoadsideAssistanceCaseCommand extends Command
{
    protected $signature = 'kanvas:movipass-setup-roadside-assistance {app_id?}';

    protected $description = 'Setup Movipass roadside assistance order type and statuses';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $app = $appId ? Apps::getById((int) $appId) : app(Apps::class);

        $cancelled = MovipassOrderStatusEnum::SERVICE_CANCELLED->value;

        new CreateOrderStatusesAction($app, OrderTypeEnum::ROADSIDE_ASSISTANCE->value, [
            MovipassOrderStatusEnum::REQUEST_SUBMITTED->value => [
                'is_default' => true,
                'transitions' => [
                    MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::DISPATCHED->value,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::DISPATCHED->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::ON_SITE->value,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::ON_SITE->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::SERVICE_COMPLETED->value,
                    MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::SERVICE_COMPLETED->value => [
                'is_final' => true,
            ],
            MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value => [
                'is_final' => true,
            ],
            MovipassOrderStatusEnum::SERVICE_CANCELLED->value => [
                'is_final' => true,
            ],
        ])->execute();

        $this->info('Movipass roadside assistance setup completed successfully.');
    }
}
