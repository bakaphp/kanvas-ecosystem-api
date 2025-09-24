<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk\Orders;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

use function Laravel\Prompts\info;

class CreateOrderStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-order-status {--app_id=} {--companies_id=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new set of status to an App';

    /**
     * @psalm-suppress MixedArgument
     *
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     * @throws RuntimeException
     * @throws NonInteractiveValidationException
     */
    public function handle(): void
    {
        $appId = 0;
        $companyId = 0;

        if ($this->option('app_id')) {
            $app = Apps::getById($this->option('app_id'));
            $this->overwriteAppService($app);
            $appId = $app->getId();
        }

        if ($this->option('companies_id')) {
            $app = Companies::getById($this->option('companies_id'));
            $companyId = $app->getId();
        }
        OrderStatus::firstOrCreate([
            'name' => OrderStatusEnum::PENDING->value,
            'apps_id' => $appId,
            'companies_id' => $companyId
        ], [
            'slug' => Str::slug(OrderStatusEnum::PENDING->value),
            'order_types_id' => 0,
            'is_default' => 1
        ]);

        OrderStatus::firstOrCreate([
            'name' => OrderStatusEnum::COMPLETED->value,
            'apps_id' => $appId,
            'companies_id' => $companyId
        ], [
            'slug' => Str::slug(OrderStatusEnum::COMPLETED->value),
            'order_types_id' => 0
        ]);

        info('Order status created successfully for app - ' . $appId);
    }
}
