<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Support\Str;
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

        $s = fn (MovipassOrderStatusEnum $e) => Str::slug($e->value);
        $cancelled = $s(MovipassOrderStatusEnum::SERVICE_CANCELLED);

        new CreateOrderStatusesAction($app, OrderTypeEnum::ROADSIDE_ASSISTANCE->value, [
            $s(MovipassOrderStatusEnum::REQUEST_SUBMITTED) => [
                'is_default' => true,
                'transitions' => [
                    $s(MovipassOrderStatusEnum::AWAITING_OPERATOR),
                    $cancelled,
                ],
            ],
            $s(MovipassOrderStatusEnum::AWAITING_OPERATOR) => [
                'transitions' => [
                    $s(MovipassOrderStatusEnum::PROVIDER_ASSIGNED),
                    $cancelled,
                ],
            ],
            $s(MovipassOrderStatusEnum::PROVIDER_ASSIGNED) => [
                'transitions' => [
                    $s(MovipassOrderStatusEnum::DISPATCHED),
                    $cancelled,
                ],
            ],
            $s(MovipassOrderStatusEnum::DISPATCHED) => [
                'transitions' => [
                    $s(MovipassOrderStatusEnum::ON_SITE),
                    $cancelled,
                ],
            ],
            $s(MovipassOrderStatusEnum::ON_SITE) => [
                'transitions' => [
                    $s(MovipassOrderStatusEnum::SERVICE_IN_PROGRESS),
                    $cancelled,
                ],
            ],
            $s(MovipassOrderStatusEnum::SERVICE_IN_PROGRESS) => [
                'transitions' => [
                    $s(MovipassOrderStatusEnum::SERVICE_COMPLETED),
                    $s(MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED),
                    $cancelled,
                ],
            ],
            $s(MovipassOrderStatusEnum::SERVICE_COMPLETED) => [
                'is_final' => true,
            ],
            $s(MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED) => [
                'is_final' => true,
            ],
            $s(MovipassOrderStatusEnum::SERVICE_CANCELLED) => [
                'is_final' => true,
            ],
        ])->execute();

        $this->info('Movipass roadside assistance setup completed successfully.');
    }
}
