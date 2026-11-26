<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Credit;

use Baka\Contracts\AppInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\FxRates\Services\FxRateService;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\SalesOrders\Enums\CreditConfigurationEnum;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;

/**
 * A customer's exposure against their credit limit: what they owe on open invoices, plus orders whose amount is not
 * carried by an effective invoice, converted to the limit's currency with the published rates. Checks gate 1
 * (confirming an order) and gate 3 (releasing the shipping documents).
 */
class CustomerCreditService
{
    public const int DEFAULT_HOLD_DAYS = 80;
    private const int DECISION_LOCK_SECONDS = 30;
    private const int DECISION_RETRY_MICROSECONDS = 250_000;

    public function __construct(
        private readonly FxRateService $rates = new FxRateService(),
        private readonly int $decisionWaitSeconds = 5,
    ) {
    }

    public function status(Organization $customer, ?SalesOrder $excluding = null): CustomerCreditStatus
    {
        $app = $customer->app;
        $company = $customer->company;
        $limit = $customer->get(CreditConfigurationEnum::CREDIT_LIMIT_AMOUNT->value);
        $currency = $customer->get(CreditConfigurationEnum::CREDIT_LIMIT_CURRENCY->value);
        $limitCurrency = $limit === null || $currency === null ? null : strtoupper((string) $currency);
        $today = Carbon::today();
        $missing = [];

        $invoices = $this->openInvoices($app, $company, $customer);
        $overdue = $invoices->filter(
            static fn (Invoice $invoice): bool => $invoice->due_date !== null && $invoice->due_date->lt($today)
        );
        $oldest = (int) $overdue
            ->map(
                static fn (Invoice $invoice): float => abs(
                    $invoice->due_date->copy()->startOfDay()->diffInDays($today)
                )
            )
            ->max();

        return new CustomerCreditStatus(
            limit: $limitCurrency === null ? null : (float) $limit,
            currency: $limitCurrency,
            openBalance: $this->sum(
                $app,
                $invoices,
                'balance_due_native',
                $limitCurrency,
                $today,
                $missing
            ),
            overdueBalance: $this->sum(
                $app,
                $overdue,
                'balance_due_native',
                $limitCurrency,
                $today,
                $missing
            ),
            overdueInvoices: $overdue->count(),
            oldestOverdueDays: $oldest,
            committedOrders: $this->sum(
                $app,
                $this->committedOrders($app, $company, $customer, $excluding),
                'order_total',
                $limitCurrency,
                $today,
                $missing
            ),
            onHold: $this->isOnHold($overdue, self::holdDaysFor($company), $today),
            exposureComplete: $missing === [],
            missingRates: array_values(array_unique($missing)),
        );
    }

    /**
     * Two orders of one customer confirmed at the same moment would each see the limit without the other, so a
     * decision that commits credit runs alone per customer. A second one waits a few seconds, then is refused.
     *
     * @template TResult
     *
     * @param Closure(): TResult $decision
     *
     * @return TResult
     */
    public function oneDecisionAtATime(Organization $customer, Closure $decision): mixed
    {
        $lock = Cache::lock(
            "scribe-credit:{$customer->apps_id}:{$customer->companies_id}:{$customer->getId()}",
            self::DECISION_LOCK_SECONDS
        );

        // Not Lock::block(): it times its wait with now(), so under a frozen clock it never gives up.
        $giveUpAt = hrtime(true) + $this->decisionWaitSeconds * 1_000_000_000;

        while (! $lock->get()) {
            if (hrtime(true) >= $giveUpAt) {
                throw new ValidationException(
                    'Another order of this customer is being confirmed right now. Try again in a moment.'
                );
            }

            usleep(self::DECISION_RETRY_MICROSECONDS);
        }

        try {
            return $decision();
        } finally {
            $lock->release();
        }
    }

    /**
     * The first day, counted from day 0 of the term, on which an unpaid invoice holds the customer: the hold days,
     * but never before the invoice is overdue.
     */
    public static function firstHoldDay(int $holdDays, int $paymentTermsDays): int
    {
        return max($holdDays, $paymentTermsDays + 1);
    }

    /**
     * Gate 1: the customer is not on hold, and the order fits in what is left of the limit.
     */
    public function assertCanConfirm(SalesOrder $order): void
    {
        $status = $this->status($order->customer, $order);

        $this->assertNotOnHold($status);

        if (! $status->hasLimit()) {
            return;
        }

        $this->assertExposureKnown($status);

        $missing = [];
        $orderAmount = $this->convert(
            $order->app,
            (float) $order->order_total,
            $order->currency,
            $status->currency,
            Carbon::today(),
            $missing
        );

        if ($orderAmount === null) {
            throw new ValidationException("Credit cannot be checked: no published rate for {$missing[0]}.");
        }

        if ($orderAmount > $status->available()) {
            throw new ValidationException(sprintf(
                'The order of %s %s is above the %s %s still available of the customer\'s credit limit.',
                $order->currency,
                number_format((float) $order->order_total, 2),
                $status->currency,
                number_format(max(0.0, $status->available()), 2)
            ));
        }
    }

    /**
     * Gate 3: the customer is not on hold, owes nothing overdue, and is inside their limit.
     */
    public function assertCanRelease(SalesOrder $order): void
    {
        $status = $this->status($order->customer);

        $this->assertNotOnHold($status);

        if ($status->overdueInvoices > 0) {
            throw new ValidationException(sprintf(
                'The customer has %d overdue invoice(s), the oldest %d days overdue: the documents are not released.',
                $status->overdueInvoices,
                $status->oldestOverdueDays
            ));
        }

        if (! $status->hasLimit()) {
            return;
        }

        $this->assertExposureKnown($status);

        if ($status->available() < 0) {
            throw new ValidationException(sprintf(
                'The customer is %s %s over their credit limit: the documents are not released.',
                $status->currency,
                number_format(abs($status->available()), 2)
            ));
        }
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function openInvoices(AppInterface $app, Companies $company, Organization $customer): Collection
    {
        return Invoice::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('customer_organization_id', $customer->getId())
            ->whereIn('document_status', [
                InvoiceDocumentStatusEnum::ISSUED->value,
                InvoiceDocumentStatusEnum::SENT->value,
            ])
            ->where('balance_due_native', '>', 0)
            ->get(['id', 'currency', 'balance_due_native', 'issued_date', 'due_date']);
    }

    /**
     * The hold counts from day 0 of the payment term, the same day the collection plan counts from: the bill of
     * lading date when the invoice belongs to a shipped order, the invoice date otherwise. An invoice that is not
     * overdue yet never holds a customer, however long its term.
     *
     * @param Collection<int, Invoice> $overdue
     */
    private function isOnHold(Collection $overdue, int $holdDays, Carbon $today): bool
    {
        if ($overdue->isEmpty()) {
            return false;
        }

        $billOfLadingDates = SalesOrderShipment::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_shipments.sales_order_id')
            ->whereIn('sales_orders.invoice_id', $overdue->pluck('id'))
            ->pluck('sales_order_shipments.bl_date', 'sales_orders.invoice_id');

        $pastHoldDays = static function (Invoice $invoice) use ($billOfLadingDates, $holdDays, $today): bool {
            $billOfLading = $billOfLadingDates[$invoice->id] ?? null;
            $termStart = $billOfLading !== null
                ? Carbon::parse($billOfLading)
                : ($invoice->issued_date ?? $invoice->due_date);

            return abs($termStart->copy()->startOfDay()->diffInDays($today)) >= $holdDays;
        };

        return $overdue->contains($pastHoldDays);
    }

    /**
     * Orders the company is committed to whose amount no invoice carries yet: no invoice at all, or one that is
     * still a draft or was voided. Once the invoice is issued its balance carries the amount instead.
     *
     * @return Collection<int, SalesOrder>
     */
    private function committedOrders(
        AppInterface $app,
        Companies $company,
        Organization $customer,
        ?SalesOrder $excluding,
    ): Collection {
        return SalesOrder::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('customer_organization_id', $customer->getId())
            ->whereIn('document_status', [
                SalesOrderStatusEnum::CONFIRMED->value,
                SalesOrderStatusEnum::ACCEPTED->value,
                SalesOrderStatusEnum::SHIPPED->value,
                SalesOrderStatusEnum::DELIVERED->value,
            ])
            ->where(
                static fn ($uninvoiced) => $uninvoiced
                    ->whereNull('invoice_id')
                    ->orWhereHas(
                        'invoice',
                        static fn ($invoice) => $invoice->whereNotIn(
                            'document_status',
                            InvoiceDocumentStatusEnum::effective()
                        )
                    )
            )
            ->when($excluding !== null, static fn ($query) => $query->where('id', '!=', $excluding->getId()))
            ->get(['currency', 'order_total']);
    }

    private function assertNotOnHold(CustomerCreditStatus $status): void
    {
        if ($status->onHold) {
            throw new ValidationException(
                "The customer has an invoice {$status->oldestOverdueDays} days overdue: their new orders are on hold."
            );
        }
    }

    private function assertExposureKnown(CustomerCreditStatus $status): void
    {
        if (! $status->exposureComplete) {
            throw new ValidationException(
                'Credit cannot be checked: no published rate for ' . implode(', ', $status->missingRates) . '.'
            );
        }
    }

    /**
     * @param iterable<int, object> $items
     * @param list<string> $missing
     */
    private function sum(
        AppInterface $app,
        iterable $items,
        string $amountColumn,
        ?string $currency,
        Carbon $date,
        array &$missing,
    ): ?float {
        if ($currency === null) {
            return null;
        }

        $total = 0.0;

        foreach ($items as $item) {
            $total += $this->convert(
                $app,
                (float) $item->{$amountColumn},
                $item->currency,
                $currency,
                $date,
                $missing
            ) ?? 0.0;
        }

        return $total;
    }

    /**
     * Zero is a real setting (hold on any overdue invoice), so only an unset value falls back to the default.
     */
    public static function holdDaysFor(Companies $company): int
    {
        $days = $company->get(CreditConfigurationEnum::CREDIT_HOLD_DAYS->value);

        return is_numeric($days) ? (int) $days : self::DEFAULT_HOLD_DAYS;
    }

    /**
     * @param list<string> $missing receives the pair when there is no rate
     */
    private function convert(
        AppInterface $app,
        float $amount,
        string $from,
        string $to,
        Carbon $date,
        array &$missing,
    ): ?float {
        $from = strtoupper($from);

        if ($from === $to) {
            return $amount;
        }

        $direct = $this->rates->rateOn($app, $from, $to, $date);

        if ($direct !== null && $direct->rate > 0) {
            return $amount * $direct->rate;
        }

        $inverse = $this->rates->rateOn($app, $to, $from, $date);

        if ($inverse !== null && $inverse->rate > 0) {
            return $amount / $inverse->rate;
        }

        $missing[] = "{$from}/{$to}";

        return null;
    }
}
