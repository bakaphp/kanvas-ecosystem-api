<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Builders\Order;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderProvider;

class ProviderOrdersBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        $providerCompanyId = (int) $args['provider_company_id'];

        $user = auth()->user();
        $userCompanyId = $user->getCurrentCompany()->getId();

        if ($userCompanyId !== $providerCompanyId) {
            throw new ValidationException('You can only view orders for your own company.');
        }

        return Order::query()
            ->join(
                OrderProvider::getQualifiedTableName() . ' as op',
                'op.order_id',
                '=',
                Order::query()->getModel()->getTable() . '.id'
            )
            ->where('op.company_id', $providerCompanyId)
            ->where('orders.apps_id', app(Apps::class)->getId())
            ->select('orders.*');
    }
}
