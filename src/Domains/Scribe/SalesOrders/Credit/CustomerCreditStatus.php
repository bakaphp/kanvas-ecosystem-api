<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Credit;

/**
 * Where a customer stands against their credit limit. Amounts are in the limit's currency; they are null when no limit
 * is set, and `exposureComplete` is false when an invoice or order could not be converted for lack of a published rate.
 */
final readonly class CustomerCreditStatus
{
    /**
     * @param list<string> $missingRates the currency pairs that had no rate, e.g. USD/DOP
     */
    public function __construct(
        public ?float $limit,
        public ?string $currency,
        public ?float $openBalance,
        public ?float $overdueBalance,
        public int $overdueInvoices,
        public int $oldestOverdueDays,
        public ?float $committedOrders,
        public bool $onHold,
        public bool $exposureComplete,
        public array $missingRates,
    ) {
    }

    /**
     * @return array{
     *     has_limit: bool,
     *     limit: float|null,
     *     currency: string|null,
     *     open_balance: float|null,
     *     overdue_balance: float|null,
     *     overdue_invoices: int,
     *     oldest_overdue_days: int,
     *     committed_orders: float|null,
     *     available: float|null,
     *     on_hold: bool,
     *     exposure_complete: bool,
     *     missing_rates: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'has_limit' => $this->hasLimit(),
            'limit' => $this->limit,
            'currency' => $this->currency,
            'open_balance' => $this->openBalance,
            'overdue_balance' => $this->overdueBalance,
            'overdue_invoices' => $this->overdueInvoices,
            'oldest_overdue_days' => $this->oldestOverdueDays,
            'committed_orders' => $this->committedOrders,
            'available' => $this->available(),
            'on_hold' => $this->onHold,
            'exposure_complete' => $this->exposureComplete,
            'missing_rates' => $this->missingRates,
        ];
    }

    public function hasLimit(): bool
    {
        return $this->limit !== null && $this->currency !== null;
    }

    public function available(): ?float
    {
        return $this->hasLimit() && $this->exposureComplete
            ? round($this->limit - (float) $this->openBalance - (float) $this->committedOrders, 2)
            : null;
    }
}
