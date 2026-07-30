<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Kanvas\Souk\Orders\Models\Order;
use Override;

class OrdersRecordExporter implements RecordExporterInterface
{
    use ReadsExportFilters;

    private const array CLOSED_STATUSES = ['completed', 'canceled', 'cancelled', 'voided'];

    #[Override]
    public function type(): string
    {
        return 'orders';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'optional status ("open" for not-closed, or an exact status), from_date, to_date (ISO)';
    }

    #[Override]
    public function headers(): array
    {
        return ['Order Number', 'Customer', 'Status', 'Currency', 'Total', 'Order Date'];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $status = $this->filterString($filters, 'status');
        $from = $this->filterString($filters, 'from_date');
        $to = $this->filterString($filters, 'to_date');

        $orders = Order::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->when($status === 'open', fn ($q) => $q->whereNotIn('status', self::CLOSED_STATUSES))
            ->when($status !== null && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', Carbon::parse((string) $from)->startOfDay()))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', Carbon::parse((string) $to)->endOfDay()))
            ->with('people')
            ->orderByDesc('created_at')
            ->limit(RecordExporterRegistry::MAX_ROWS)
            ->get();

        return $orders->map(function (Order $order): array {
            $people = $order->people;
            $customer = $people !== null ? trim($people->firstname . ' ' . $people->lastname) : '';

            return [
                $order->order_number,
                $customer !== '' ? $customer : ($order->user_email ?? ''),
                $order->status ?? '',
                $order->currency ?? '',
                (float) $order->total_gross_amount,
                $order->created_at?->toDateString() ?? '',
            ];
        })->all();
    }
}
