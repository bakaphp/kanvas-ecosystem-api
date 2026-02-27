<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Souk\Orders\Exports\OrderPaymentsStatsExportExcel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Maatwebsite\Excel\Facades\Excel;

class ExportOrderPaymentsAction
{
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

        $stats = new GetOrderPaymentStatsAction(
            app: $this->app,
            paidStates: $this->paidStates,
            orderTypeNames: $this->orderTypeNames,
        )->execute(
            startDate: $this->startDate,
            endDate: $this->endDate,
            timezone: $this->timezone,
        );

        $stats['totals'] = [
            'total_transactions' => $stats['ordersInPeriod']['count'] ?? 0,
            'total_amount'       => $stats['ordersInPeriod']['totalAmount'] ?? 0,
        ];

        $orders = Order::query()
            ->where('orders.apps_id', $this->app->getId())
            ->whereIn('orders.payment_status', $this->paidStates)
            ->when(! empty($this->orderTypeNames), function ($q) {
                $q->join('order_types', 'orders.order_types_id', '=', 'order_types.id')
                    ->whereIn('order_types.name', $this->orderTypeNames);
            })
            ->where('orders.total_net_amount', '>', 0)
            ->when($start, fn ($q) => $q->where('orders.created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('orders.created_at', '<=', $end))
            ->with('user')
            ->orderBy('orders.created_at', 'asc')
            ->get();

        $timestamp    = Carbon::now()->format('Y-m-d');
        $filename     = "order_payments_export_{$timestamp}";
        $tempFilePath = "exports/{$filename}.xlsx";

        Excel::store(
            new OrderPaymentsStatsExportExcel($stats, $orders, $this->timezone, $this->fieldMapper, $this->language),
            $tempFilePath,
            'public'
        );

        $fullTempPath = Storage::disk('public')->path($tempFilePath);

        $uploadedFile = new UploadedFile(
            $fullTempPath,
            "{$filename}.xlsx",
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $filesystem        = new FilesystemServices($this->app);
        $uploadedFileEntry = $filesystem->upload($uploadedFile, $this->user);

        $isSavedInFileSystem = ! empty($uploadedFileEntry->url) && $uploadedFileEntry->url !== '/';
        if ($isSavedInFileSystem && file_exists($fullTempPath)) {
            unlink($fullTempPath);
            Storage::disk('public')->delete($tempFilePath);
        }

        return [
            'status'       => 'success',
            'download_url' => $isSavedInFileSystem ? $uploadedFileEntry->url : Storage::disk('public')->url($tempFilePath),
            'file_name'    => "{$filename}.xlsx",
            'file_path'    => $uploadedFileEntry->url ?? $tempFilePath,
            'message'      => 'Excel export completed successfully',
        ];
    }
}
