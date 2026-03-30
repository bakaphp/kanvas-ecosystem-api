<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Tookan;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Tookan\Enums\OrderStatusEnum;
use Kanvas\Connectors\Tookan\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\CreateOrderStatusesAction;

class SetupGifteaOrderCommand extends Command
{
    protected $signature = 'kanvas:tookan-setup-giftea-order {app_id?}';

    protected $description = 'Setup Giftea delivery order types and statuses (parent + provider)';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $app = $appId ? Apps::getById((int) $appId) : app(Apps::class);

        $cancelled = OrderStatusEnum::CANCELLED->value;

        // Parent (Giftea customer) order — full flow including wrapping steps
        $parentResult = new CreateOrderStatusesAction($app, OrderTypeEnum::DELIVERY->value, [
            OrderStatusEnum::RECEIVED->value => [
                'is_default' => true,
                'transitions' => [
                    OrderStatusEnum::CHECKING_STOCK->value,
                    OrderStatusEnum::PREPARING->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::CHECKING_STOCK->value => [
                'transitions' => [
                    OrderStatusEnum::PREPARING->value,
                    OrderStatusEnum::OUT_OF_STOCK->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::PREPARING->value => [
                'transitions' => [
                    OrderStatusEnum::READY_FOR_PICKUP->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::READY_FOR_PICKUP->value => [
                'transitions' => [
                    OrderStatusEnum::PREPARING_PACKAGING->value,
                    OrderStatusEnum::PACKAGING_READY->value,
                    OrderStatusEnum::DISPATCHED->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::PREPARING_PACKAGING->value => [
                'transitions' => [
                    OrderStatusEnum::PACKAGING_READY->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::PACKAGING_READY->value => [
                'transitions' => [
                    OrderStatusEnum::DISPATCHED->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::DISPATCHED->value => [
                'transitions' => [
                    OrderStatusEnum::DELIVERED->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::DELIVERED->value => [
                'is_final' => true,
            ],
            OrderStatusEnum::OUT_OF_STOCK->value => [
                'is_final' => true,
            ],
            $cancelled => [
                'is_final' => true,
            ],
        ])->execute();

        // Child (provider) order — simpler flow, no wrapping steps
        $providerResult = new CreateOrderStatusesAction($app, OrderTypeEnum::DELIVERY_PROVIDER->value, [
            OrderStatusEnum::RECEIVED->value => [
                'is_default' => true,
                'transitions' => [
                    OrderStatusEnum::CHECKING_STOCK->value,
                    OrderStatusEnum::PREPARING->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::CHECKING_STOCK->value => [
                'transitions' => [
                    OrderStatusEnum::PREPARING->value,
                    OrderStatusEnum::OUT_OF_STOCK->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::PREPARING->value => [
                'transitions' => [
                    OrderStatusEnum::READY_FOR_PICKUP->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::READY_FOR_PICKUP->value => [
                'transitions' => [
                    OrderStatusEnum::DISPATCHED->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::DISPATCHED->value => [
                'transitions' => [
                    OrderStatusEnum::DELIVERED->value,
                    $cancelled,
                ],
            ],
            OrderStatusEnum::DELIVERED->value => [
                'is_final' => true,
            ],
            OrderStatusEnum::OUT_OF_STOCK->value => [
                'is_final' => true,
            ],
            $cancelled => [
                'is_final' => true,
            ],
        ])->execute();

        $this->info("Giftea order types set up for app: {$app->name}");

        $this->info('');
        $this->info('Parent order type: ' . OrderTypeEnum::DELIVERY->value);
        $this->table(
            ['Status', 'Slug', 'Default', 'Final'],
            collect($parentResult['statuses'])->map(
                fn ($s) => [$s->name, $s->slug, $s->is_default ? 'yes' : 'no', $s->is_final ? 'yes' : 'no']
            )->toArray()
        );

        $this->info('');
        $this->info('Provider order type: ' . OrderTypeEnum::DELIVERY_PROVIDER->value);
        $this->table(
            ['Status', 'Slug', 'Default', 'Final'],
            collect($providerResult['statuses'])->map(
                fn ($s) => [$s->name, $s->slug, $s->is_default ? 'yes' : 'no', $s->is_final ? 'yes' : 'no']
            )->toArray()
        );
    }
}
