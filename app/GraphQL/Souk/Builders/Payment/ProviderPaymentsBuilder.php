<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Builders\Payment;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderProvider;
use Kanvas\Souk\Payments\Models\Payments;

class ProviderPaymentsBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        $providerCompanyId = (int) $args['provider_company_id'];

        $user = auth()->user();
        $userCompanyId = $user->getCurrentCompany()->getId();

        if ($userCompanyId !== $providerCompanyId) {
            throw new ValidationException('You can only view payments for your own company.');
        }

        $orderSubquery = Order::query()
            ->join(
                OrderProvider::getQualifiedTableName() . ' as op',
                'op.order_id',
                '=',
                'orders.id'
            )
            ->where('op.company_id', $providerCompanyId)
            ->where('orders.apps_id', app(Apps::class)->getId())
            ->select('orders.id');

        return Payments::query()
            ->whereIn('payable_id', $orderSubquery)
            ->where('payable_type', Order::class)
            ->where('payments.apps_id', app(Apps::class)->getId())
            ->where('payments.is_deleted', 0);
    }
}
