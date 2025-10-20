<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Kanvas\Apps\Models\Apps;
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
    protected $signature = 'kanvas:create-order-status {--app_id=} {--name}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new set of order status global or to an App';

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
        $data = $this->options();

        if (! empty($data['app_id']) || ! empty($data['name'])) {
            // Validation
            $validator = Validator::make($data, [
                'app_id' => 'required_with:name',
                'name' => 'required_with:app_id',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }
                return;
            }

            $app = Apps::getById($this->option('app_id'));
            $this->overwriteAppService($app);
            $this->createPrivateOrderStatus($app, $data['name']);
            return;
        }

        $this->createGlobalOrderStatus();
    }

    public function createGlobalOrderStatus(): void
    {
        OrderStatus::firstOrCreate([
            'name' => OrderStatusEnum::PENDING->value,
            'apps_id' => 0,
            'companies_id' => 0,
            'order_types_id' => 0,
        ], [
            'slug' => Str::slug(OrderStatusEnum::PENDING->value),
        ]);

        OrderStatus::firstOrCreate([
            'name' => OrderStatusEnum::COMPLETED->value,
            'apps_id' => 0,
            'companies_id' => 0,
            'order_types_id' => 0,
        ], [
            'slug' => Str::slug(OrderStatusEnum::COMPLETED->value),
        ]);

        OrderStatus::firstOrCreate([
            'name' => OrderStatusEnum::DRAFT->value,
            'apps_id' => 0,
            'companies_id' => 0,
            'order_types_id' => 0,
        ], [
            'slug' => Str::slug(OrderStatusEnum::DRAFT->value),
        ]);

        OrderStatus::firstOrCreate([
            'name' => OrderStatusEnum::CANCELED->value,
            'apps_id' => 0,
            'companies_id' => 0,
            'order_types_id' => 0,
        ], [
            'slug' => Str::slug(OrderStatusEnum::CANCELED->value),
        ]);

        OrderStatus::firstOrCreate([
            'name' => OrderStatusEnum::FAILED->value,
            'apps_id' => 0,
            'companies_id' => 0,
            'order_types_id' => 0,
        ], [
            'slug' => Str::slug(OrderStatusEnum::FAILED->value),
        ]);

        info('Order status created for all apps');
    }

    public function createPrivateOrderStatus(Apps $app, String $name): void
    {
        OrderStatus::firstOrCreate([
            'name' => $name,
            'apps_id' => (int) $app->getId(),
        ], [
            'slug' => Str::slug($name),
            'companies_id' => 0,
        ]);

        info('Order Status ' . $name . ' created for app - ' . $app->getId());
    }
}
