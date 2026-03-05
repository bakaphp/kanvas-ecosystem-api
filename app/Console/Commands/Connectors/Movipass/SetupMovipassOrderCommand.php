<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\CreateOrderStatusesAction;

class SetupMovipassOrderCommand extends Command
{
    protected $signature = 'kanvas:movipass-setup-movipass-order {app_id?}';

    protected $description = 'Setup Movipass parking reservation order type and statuses';

    public function handle(): void
    {
        $appId = $this->argument('app_id');
        $app = $appId ? Apps::getById((int) $appId) : app(Apps::class);

        $cancelled = MovipassOrderStatusEnum::CANCELLED->value;

        $result = new CreateOrderStatusesAction($app, OrderTypeEnum::MOVIPASS->value, [
            MovipassOrderStatusEnum::CREATED->value => [
                'is_default' => true,
                'transitions' => [
                    MovipassOrderStatusEnum::ACTIVE->value,
                    MovipassOrderStatusEnum::PAID->value,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::PAID->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::ACTIVE->value,    // non-manual
                    MovipassOrderStatusEnum::COMPLETED->value, // manual
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::ACTIVE->value => [
                'transitions' => [
                    MovipassOrderStatusEnum::PAID->value,  // manual orders only (enforced at runtime)
                    MovipassOrderStatusEnum::COMPLETED->value,
                    $cancelled,
                ],
            ],
            MovipassOrderStatusEnum::COMPLETED->value => [
                'is_final' => true,
            ],
            $cancelled => [
                'is_final' => true,
            ],
        ])->execute();

        // Mark order type as expirable for slot availability tracking
        $orderType = $result['order_type'];
        if (! $orderType->isExpirable()) {
            $orderType->config = ['expirable' => true];
            $orderType->save();
        }

        $this->info("Movipass order type and statuses set up for app: {$app->name}");
        $this->table(
            ['Status', 'Slug', 'Final'],
            collect($result['statuses'])->map(fn ($s) => [$s->name, $s->slug, $s->is_final ? 'yes' : 'no'])->toArray()
        );
    }
}
