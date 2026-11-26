<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Kpis;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;

/**
 * The order-to-cash indicators of the commercial flow, from the timestamps the sales orders already carry. Each one
 * counts the orders whose own event falls in the period: confirmed, shipped, delivered or paid.
 *
 * Not measured: the hand-off to Operations within 4 business hours, because the handing over is the confirmation
 * itself and has no separate event.
 *
 * @phpstan-type Indicator array{
 *     key: string,
 *     label: string,
 *     unit: string,
 *     value: float|null,
 *     target: float,
 *     target_kind: string,
 *     met: bool|null,
 *     numerator: int|null,
 *     denominator: int|null
 * }
 */
class OrderKpiService
{
    public const int ACCEPTANCE_HOURS = 72;

    /**
     * @return list<Indicator>
     */
    public function report(AppInterface $app, Companies $company, Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $days = [$start->toDateString(), $end->toDateString()];

        // Only orders with an event in the period: every indicator below counts one of these four dates.
        $orders = SalesOrder::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where(
                static fn ($inPeriod) => $inPeriod
                    ->whereBetween('confirmed_at', [$start, $end])
                    ->orWhereHas(
                        'shipment',
                        static fn ($shipment) => $shipment->where(
                            static fn ($dates) => $dates
                                ->whereBetween('bl_date', $days)
                                ->orWhereBetween('delivered_on', $days)
                        )
                    )
                    ->orWhereHas(
                        'invoice',
                        static fn ($invoice) => $invoice->whereBetween('paid_at', [$start, $end])
                    )
            )
            ->with(['shipment', 'invoice'])
            ->get();

        $confirmed = $orders->filter(fn (SalesOrder $order): bool => $this->within($order->confirmed_at, $from, $to));
        $isAnswered = static fn (SalesOrder $order): bool => $order->accepted_at !== null
            || $order->returned_at !== null;
        $answered = $confirmed->filter($isAnswered);
        // An order still waiting past the window has already missed it, so it counts against the target now
        // instead of dropping out until someone answers.
        $dueForAnswer = $confirmed->filter(
            static fn (SalesOrder $order): bool => $isAnswered($order)
                || $order->confirmed_at->diffInHours(Carbon::now()) > self::ACCEPTANCE_HOURS
        );
        $shipped = $orders->filter(
            fn (SalesOrder $order): bool => $this->within($order->shipment?->bl_date, $from, $to)
        );
        $delivered = $orders->filter(
            fn (SalesOrder $order): bool => $this->within($order->shipment?->delivered_on, $from, $to)
        );
        $paid = $orders->filter(
            fn (SalesOrder $order): bool => $order->shipment !== null
                && $order->invoice?->document_status === InvoiceDocumentStatusEnum::PAID
                && $this->within($order->invoice->paid_at, $from, $to)
        );

        return [
            $this->percentage(
                'acceptance_within_72h',
                'Orders accepted within 72 hours of being confirmed',
                95.0,
                'min',
                $dueForAnswer->filter(static fn (SalesOrder $order): bool => $order->accepted_at !== null
                    && $order->confirmed_at->diffInHours($order->accepted_at) <= self::ACCEPTANCE_HOURS),
                $dueForAnswer
            ),
            $this->percentage(
                'returned_at_gate_2',
                'Orders Operations returned to Commercial',
                5.0,
                'max',
                $answered->filter(static fn (SalesOrder $order): bool => $order->returned_at !== null),
                $answered
            ),
            $this->percentage(
                'right_first_time',
                'Orders confirmed with no difference found in the review',
                90.0,
                'min',
                $confirmed->filter(static fn (SalesOrder $order): bool => $order->differences() === []),
                $confirmed
            ),
            $this->percentage(
                'shipped_on_time',
                'Shipments whose bill of lading is on or before the promised departure',
                90.0,
                'min',
                $shipped->filter(static fn (SalesOrder $order): bool => $order->etd !== null
                    && $order->shipment->bl_date->lte($order->etd)),
                $shipped->filter(static fn (SalesOrder $order): bool => $order->etd !== null)
            ),
            $this->percentage(
                'documents_on_time',
                'Documents packages sent by their due date',
                95.0,
                'min',
                $shipped->filter(
                    static fn (SalesOrder $order): bool => $order->shipment->documents_package_sent_at !== null
                        && $order->shipment->documents_package_sent_at->lte(
                            $order->shipment->documents_package_due_on->copy()->endOfDay()
                        )
                ),
                $shipped->filter(
                    static fn (SalesOrder $order): bool => $order->shipment->documents_package_sent_at !== null
                        || $order->shipment->documents_package_due_on->lt(Carbon::today())
                )
            ),
            $this->percentage(
                'perfect_delivery',
                'Deliveries on time, complete and undamaged',
                90.0,
                'min',
                $delivered->filter(
                    static fn (SalesOrder $order): bool => (
                        $order->eta === null || $order->shipment->delivered_on->lte($order->eta)
                    )
                        && $order->shipment->delivery_discrepancies === null
                        && ! $order->shipment->damage_reported
                ),
                $delivered
            ),
            $this->percentage(
                'collected_on_time',
                'Invoices paid within the payment term counted from the bill of lading',
                90.0,
                'min',
                $paid->filter(
                    static fn (SalesOrder $order): bool => $order->invoice->paid_at->lte(
                        $order->shipment->due_date->copy()->endOfDay()
                    )
                ),
                $paid
            ),
            $this->averageDaysToCollect($paid),
        ];
    }

    /**
     * @param Collection<int, SalesOrder> $matching
     * @param Collection<int, SalesOrder> $total
     *
     * @return Indicator
     */
    private function percentage(
        string $key,
        string $label,
        float $target,
        string $kind,
        Collection $matching,
        Collection $total,
    ): array {
        return $this->indicator(
            $key,
            $label,
            '%',
            $total->isEmpty() ? null : round($matching->count() / $total->count() * 100, 1),
            $target,
            $kind,
            $matching->count(),
            $total->count()
        );
    }

    /**
     * @param Collection<int, SalesOrder> $paid
     *
     * @return Indicator
     */
    private function averageDaysToCollect(Collection $paid): array
    {
        $average = $paid->isEmpty() ? null : round(
            (float) $paid->avg(
                static fn (SalesOrder $order): float => abs(
                    $order->shipment->bl_date->copy()->startOfDay()->diffInDays($order->invoice->paid_at)
                )
            ),
            1
        );
        $target = $paid->isEmpty()
            ? 50.0
            : round((float) $paid->avg(static fn (SalesOrder $order): int => $order->shipment->payment_terms_days), 1);

        return $this->indicator(
            'average_days_to_collect',
            'Average days from the bill of lading to payment',
            'days',
            $average,
            $target,
            'max',
            null,
            $paid->count()
        );
    }

    /**
     * @return Indicator
     */
    private function indicator(
        string $key,
        string $label,
        string $unit,
        ?float $value,
        float $target,
        string $kind,
        ?int $numerator,
        ?int $denominator,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'unit' => $unit,
            'value' => $value,
            'target' => $target,
            'target_kind' => $kind,
            'met' => $value === null ? null : ($kind === 'min' ? $value >= $target : $value <= $target),
            'numerator' => $numerator,
            'denominator' => $denominator,
        ];
    }

    private function within(?Carbon $moment, Carbon $from, Carbon $to): bool
    {
        return $moment !== null && $moment->between($from->copy()->startOfDay(), $to->copy()->endOfDay());
    }
}
