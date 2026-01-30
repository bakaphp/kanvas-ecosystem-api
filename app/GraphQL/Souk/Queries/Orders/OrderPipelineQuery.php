<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Actions\GetOrderPipelineAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Services\B2BConfigurationService;

class OrderPipelineQuery
{
    public function resolve(mixed $root, array $args): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = B2BConfigurationService::getConfiguredB2BCompany($app, $user->getCurrentCompany());

        $order = Order::where([
            'apps_id' => $app->getId(),
            'id' => $args['order_id'],
            'companies_id' => $company->getId(),
        ])->first();

        if (! $order) {
            throw new ValidationException('Order not found');
        }

        return new GetOrderPipelineAction($order)->execute();
    }
}
