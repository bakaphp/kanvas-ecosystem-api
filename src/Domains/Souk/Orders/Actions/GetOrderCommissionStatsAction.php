<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Orders\DataTransferObject\CommissionStats;

class GetOrderCommissionStatsAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly ?CompanyInterface $company,
        protected readonly Carbon $from,
        protected readonly Carbon $to,
        protected readonly ?string $orderType,
    ) {
    }

    public function execute(): CommissionStats
    {
        $query = DB::connection('commerce')
            ->table('orders')
            ->selectRaw('
                COALESCE(SUM(total_net_amount), 0) as total_revenue,
                COALESCE(SUM(commission_amount), 0) as total_commission,
                COALESCE(SUM(provider_amount), 0) as total_provider_amount,
                COUNT(*) as order_count
            ')
            ->where('orders.apps_id', $this->app->getId())
            ->whereNotNull('orders.commission_rate')
            ->where('orders.is_deleted', 0)
            ->whereBetween('orders.created_at', [$this->from, $this->to]);

        if ($this->company !== null) {
            $query->where('orders.companies_id', $this->company->getId());
        }

        if ($this->orderType !== null) {
            $query->join('order_types', 'orders.order_types_id', '=', 'order_types.id')
                ->where('order_types.name', $this->orderType);
        }

        $result = $query->first();

        return new CommissionStats(
            total_revenue: (float) $result->total_revenue,
            total_commission: (float) $result->total_commission,
            total_provider_amount: (float) $result->total_provider_amount,
            order_count: (int) $result->order_count,
        );
    }
}
