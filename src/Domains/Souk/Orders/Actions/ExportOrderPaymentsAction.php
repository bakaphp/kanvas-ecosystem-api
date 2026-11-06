<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\Concerns\StoresExcelExport;
use Kanvas\Souk\Orders\Exports\OrderPaymentsStatsExportExcel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class ExportOrderPaymentsAction
{
    use StoresExcelExport;

    public function __construct(
        protected readonly Apps $app,
        protected readonly Users $user,
        protected readonly array $paidStates = ['paid'],
        protected readonly array $orderTypeNames = [],
        protected readonly string $timezone = 'UTC',
        protected readonly ?string $startDate = null,
        protected readonly ?string $endDate = null,
        protected readonly ?array $fieldMapper = null,
        protected readonly string $language = 'en',
        protected readonly ?string $userEmail = null,
        protected readonly array $providerCompanyIds = [],
        protected readonly ?array $metadata = null,
        protected readonly bool $includeSummary = true,
    ) {
    }

    public function execute(): array
    {
        $start = $this->startDate
            ? Carbon::parse($this->startDate, $this->timezone)->startOfDay()->setTimezone('UTC')
            : null;

        $end = $this->endDate
            ? Carbon::parse($this->endDate, $this->timezone)->endOfDay()->setTimezone('UTC')
            : null;

        $stats = [];
        if ($this->includeSummary) {
            $stats = new GetOrderPaymentStatsAction(
                app: $this->app,
                paidStates: $this->paidStates,
                orderTypeNames: $this->orderTypeNames,
                userEmail: $this->userEmail,
                providerCompanyIds: $this->providerCompanyIds,
            )->execute(
                startDate: $this->startDate,
                endDate: $this->endDate,
                timezone: $this->timezone,
            );

            $stats['totals'] = [
                'total_transactions' => $stats['ordersInPeriod']['count'] ?? 0,
                'total_amount'       => $stats['ordersInPeriod']['totalAmount'] ?? 0,
            ];
        }

        $orders = Order::query()
            ->select('orders.*')
            ->where('orders.apps_id', $this->app->getId())
            ->whereIn('orders.payment_status', $this->paidStates)
            ->when(! empty($this->orderTypeNames), function ($q) {
                $q->join('order_types', 'orders.order_types_id', '=', 'order_types.id')
                    ->whereIn('order_types.name', $this->orderTypeNames);
            })
            ->where('orders.total_net_amount', '>', 0)
            ->when($start, fn ($q) => $q->where('orders.created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('orders.created_at', '<=', $end))
            ->when($this->userEmail, fn ($q) => $q->where('orders.user_email', 'LIKE', $this->userEmail))
            ->when(! empty($this->providerCompanyIds), fn ($q) => $q->whereIn(
                'orders.id',
                DB::connection('commerce')
                    ->table('order_providers')
                    ->whereIn('company_id', $this->providerCompanyIds)
                    ->select('order_id')
            ))
            ->with('user')
            ->orderBy('orders.created_at', 'asc')
            ->get();

        $result = $this->storeExcelExport(
            new OrderPaymentsStatsExportExcel(
                $stats,
                $orders,
                $this->timezone,
                $this->fieldMapper,
                $this->language,
                $this->metadata,
                $this->includeSummary,
            ),
            'order_payments_export_' . Carbon::now()->format('Y-m-d'),
            $this->app,
            $this->user,
        );

        OrderPaymentsStatsExportExcel::cleanupHeaderLogoFiles();

        return $result;
    }
}
