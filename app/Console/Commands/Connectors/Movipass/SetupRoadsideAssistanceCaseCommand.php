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
        $requestSubmitted = MovipassOrderStatusEnum::REQUEST_SUBMITTED->value;
        // Declining a case is only reachable from the two stages before a provider is assigned;
        // once a unit is on the way the case has to be cancelled, not declined.
        $notAuthorized = MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->value;

        new CreateOrderStatusesAction($app, OrderTypeEnum::ROADSIDE_ASSISTANCE->value, [
            MovipassOrderStatusEnum::REQUEST_SUBMITTED->value => [
                'is_default' => true,
                'transitions' => [
                    MovipassOrderStatusEnum::AWAITING_OPERATOR->value,
                    $cancelled,
                    $notAuthorized,
                ],
            ],
            MovipassOrderStatusEnum::AWAITING_OPERATOR->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
                    $requestSubmitted,
                    $cancelled,
                    $notAuthorized,
                ],
            ],
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::DISPATCHED->value,
                    $requestSubmitted,
                    $cancelled,
                ],
            ],
            // An incident can be raised while tracking the unit, before the service ever starts, so
            // the unresolved ending has to be reachable from the tracking stages too — otherwise
            // "closed with incident" would silently leave the order sitting in dispatched/on_site.
            MovipassOrderStatusEnum::DISPATCHED->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::ON_SITE->value,
                    MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value,
                    $requestSubmitted,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::ON_SITE->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value,
                    MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value,
                    $requestSubmitted,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::SERVICE_COMPLETED->value,
                    MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value,
                    $requestSubmitted,
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
            $notAuthorized => [
                'is_final' => true,
            ],
        ])->execute();

        $this->info('Movipass roadside assistance setup completed successfully.');
    }
}
