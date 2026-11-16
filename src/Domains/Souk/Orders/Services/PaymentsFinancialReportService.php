<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\DataTransferObject\PaymentsFinancialReport;
use Kanvas\Souk\Orders\Helpers\DateHelper;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Repositories\OrderPaymentRepository;
use Kanvas\Souk\Payments\Enums\PaymentChannelEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class PaymentsFinancialReportService
{
    public function __construct(
        private readonly PaymentsFinancialReport $report,
    ) {
    }

    public function build(): array
    {
        $timezone = $this->report->timezone;
        $start = Carbon::parse($this->report->startDate, $timezone)->startOfDay()->setTimezone('UTC');
        $end = Carbon::parse($this->report->endDate, $timezone)->endOfDay()->setTimezone('UTC');

        $rates = [
            PaymentChannelEnum::DIGITAL->value => $this->feeRate(ConfigurationEnum::PAYMENT_REPORT_DIGITAL_FEE_RATE),
            PaymentChannelEnum::PHYSICAL->value => $this->feeRate(ConfigurationEnum::PAYMENT_REPORT_PHYSICAL_FEE_RATE),
        ];

        $transactions = $this->transactions($start, $end);
        $daily = $this->daily(
            $transactions,
            $start,
            $end,
            $rates
        );

        return [
            'start_date' => $start->copy()->setTimezone($timezone)->format('Y-m-d'),
            'end_date' => $end->copy()->setTimezone($timezone)->format('Y-m-d'),
            'location' => $this->report->variant?->name,
            'channel' => $this->report->channel,
            'rates' => $rates,
            'summary' => $this->summary($daily),
            'daily' => $daily,
            'transactions' => $transactions,
        ];
    }

    public static function net(float $gross, float $rate): float
    {
        return round($gross - round($gross * $rate, 2), 2);
    }

    private function feeRate(ConfigurationEnum $key): float
    {
        return (float) ($this->report->app->get($key->value) ?? 0);
    }

    private function transactions(Carbon $start, Carbon $end): Collection
    {
        $paidDates = new OrderPaymentRepository($this->report->app)->getPaidOrderDates(
            start: $start,
            end: $end,
            paidStates: $this->report->paidStates,
            variantId: $this->report->variant?->getId(),
            orderTypeNames: $this->report->orderTypeNames,
            providerCompanyIds: $this->report->providerCompanyIds,
            userEmail: $this->report->userEmail,
        );

        if ($paidDates->isEmpty()) {
            return collect();
        }

        $orders = Order::query()
            ->whereIn('id', $paidDates->keys())
            ->with(['payments', 'orderStatus'])
            ->get()
            ->keyBy('id');

        return $paidDates
            ->map(function (string $paidAt, int|string $orderId) use ($orders) {
                $order = $orders->get((int) $orderId);
                if (! $order) {
                    return null;
                }

                return [
                    'paid_at' => Carbon::parse($paidAt, 'UTC')->setTimezone($this->report->timezone),
                    'order_number' => (string) $order->order_number,
                    'amount' => (float) $order->total_net_amount,
                    'channel' => PaymentChannelEnum::fromMethod(
                        $order->payments->firstWhere('status', PaymentStatusEnum::PAID->value)?->payment_method
                    ),
                    'status' => (string) ($order->orderStatus?->name ?? $order->status),
                ];
            })
            ->filter()
            ->when(
                $this->report->channel !== null,
                fn (Collection $rows) => $rows->filter(fn (array $row) => $row['channel'] === $this->report->channel)
            )
            ->values();
    }

    private function daily(
        Collection $transactions,
        Carbon $start,
        Carbon $end,
        array $rates
    ): array {
        $byDate = $transactions->groupBy(fn (array $row) => $row['paid_at']->format('Y-m-d'));
        $days = [];

        foreach (DateHelper::generateDateList($start, $end, $this->report->timezone) as $quotedDate) {
            $date = trim($quotedDate, "'");
            $rows = $byDate->get($date, collect());
            $day = ['date' => $date];

            foreach (PaymentChannelEnum::cases() as $channel) {
                $channelRows = $rows->filter(fn (array $row) => $row['channel'] === $channel);
                $gross = round((float) $channelRows->sum('amount'), 2);

                $day[$channel->value] = [
                    'count' => $channelRows->count(),
                    'gross' => $gross,
                    'net' => self::net($gross, $rates[$channel->value]),
                ];
            }

            $day['count'] = $day[PaymentChannelEnum::DIGITAL->value]['count'] + $day[PaymentChannelEnum::PHYSICAL->value]['count'];
            $day['gross'] = round($day[PaymentChannelEnum::DIGITAL->value]['gross'] + $day[PaymentChannelEnum::PHYSICAL->value]['gross'], 2);
            $day['net'] = round($day[PaymentChannelEnum::DIGITAL->value]['net'] + $day[PaymentChannelEnum::PHYSICAL->value]['net'], 2);

            $days[] = $day;
        }

        return $days;
    }

    private function summary(array $daily): array
    {
        $days = collect($daily);
        $summary = [];

        foreach (PaymentChannelEnum::cases() as $channel) {
            $summary[$channel->value] = [
                'count' => $days->sum(fn (array $day) => $day[$channel->value]['count']),
                'gross' => round((float) $days->sum(fn (array $day) => $day[$channel->value]['gross']), 2),
                'net' => round((float) $days->sum(fn (array $day) => $day[$channel->value]['net']), 2),
            ];
        }

        $summary['count'] = $days->sum('count');
        $summary['gross'] = round((float) $days->sum('gross'), 2);
        $summary['net'] = round((float) $days->sum('net'), 2);

        return $summary;
    }
}
