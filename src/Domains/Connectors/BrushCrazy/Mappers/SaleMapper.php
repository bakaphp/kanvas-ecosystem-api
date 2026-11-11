<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Support\AddonPricer;
use Kanvas\Connectors\BrushCrazy\Support\Money;
use Kanvas\Connectors\BrushCrazy\Support\SaleRows;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

/**
 * Turns one BrushCrazy sale — with its partial-refund mirrors — into the values of one Souk Order
 * and its items. No lookups, so it stays unit-testable; the sync action resolves the tenant,
 * buyer and variants and writes.
 *
 * BrushCrazy stores no totals. Everything here re-derives them the way `Sale` and `SaleItem` do:
 *   item total = (price + add-ons − item discount) × quantity, or 0 for a giveaway
 *   subtotal   = Σ totals of the items not refunded
 *   total      = subtotal − sale discounts + taxes (taxes are 0 when the sale is tax exempt)
 *   balance    = total − payments
 *
 * Refunds come in three shapes and none of them is a revenue row of its own:
 * - full refund: the sale itself gets `refunded_at`; its items and total stay as they were.
 * - item refund: a mirror sale (`original_sale` = this one) receives a copy of the line, and the
 *   original line is stamped `refunded_at` — or, at quantity > 1, just decremented. So this sale's
 *   total already excludes it.
 * - amount refund: a mirror sale with one "Refund" line. This sale's items and total are untouched.
 * The mirrors are netted into the parent's metadata, never imported as orders.
 *
 * Money in metadata is integer cents; the Order's own columns are dollars, as Souk stores them.
 */
class SaleMapper
{
    /** BrushCrazy's payment type for money the Kanvas site charged (KanvasSaleBooker::PAYMENT_TYPE). */
    public const string KANVAS_PAYMENT_TYPE = 'Kanvas';

    /** Written by BrushCrazy's KanvasRefunds on the payment rows of a refund Kanvas pushed. */
    public const string KANVAS_REFUND_APPROVAL_CODE = 'KANVAS-RF';
    public const string KANVAS_REFUND_CARD_PREFIX = 'kanvas-refund:';

    /**
     * @param  array<int, SaleRows>  $refunds  the sale's mirror sales that completed (`refunded_at` set)
     * @return array{
     *     importable: bool,
     *     order: array<string, mixed>,
     *     items: array<int, array{sale_item_id: int, product_id: int|null, kind: SaleLineKindEnum, attributes: array<string, mixed>}>
     * }
     */
    public static function map(SaleRows $sale, array $refunds, AddonPricer $pricer): array
    {
        $row = $sale->sale;
        $totals = self::totals($sale, $pricer);
        $refundSummary = self::refundSummary($refunds, $pricer);
        $isFullyRefunded = ($row->refunded_at ?? null) !== null;
        $refundTotals = self::refundTotals($sale, $totals, $refundSummary);
        $roundingCents = self::refundRoundingCents($sale, $refundSummary);
        $paymentStatus = self::paymentStatus($isFullyRefunded, $refundTotals['balance_after_refunds_cents'], $roundingCents);

        return [
            'importable' => self::isImportable($sale),
            'order' => [
                'order_number' => $sale->id(),
                'reference' => Str::trimToNull($row->ulid ?? null),
                'status' => OrderStatusEnum::COMPLETED->value,
                'fulfillment_status' => OrderFulfillmentStatusEnum::COMPLETED->value,
                // Before paid_at: the Order mutator on payment_status rewrites paid_at.
                'payment_status' => $paymentStatus->value,
                'paid_at' => $paymentStatus === PaymentStatusEnum::PAID ? ($row->completed_at ?? null) : null,
                'currency' => 'USD',
                'total_gross_amount' => Money::toDollars($totals['total_cents']),
                'total_net_amount' => Money::toDollars($totals['total_cents'] - $totals['tax_cents']),
                'discount_amount' => Money::toDollars($totals['discount_cents'] + $totals['item_discount_cents']),
                'tax_amount' => Money::toDollars($totals['tax_cents']),
                'shipping_price_gross_amount' => 0.0,
                'shipping_price_net_amount' => 0.0,
                'discount_name' => self::discountName($sale->discounts),
                'payment_gateway_names' => array_values(array_unique(array_map(fn (object $payment) => (string) $payment->type, $sale->payments))),
                'is_deleted' => ($row->deleted_at ?? null) !== null,
                'created_at' => $row->created_at ?? null,
                'metadata' => [
                    'brushcrazy' => [
                        'sale_id' => $sale->id(),
                        'studio_id' => isset($row->studio_id) ? (int) $row->studio_id : null,
                        'customer_id' => isset($row->customer_id) ? (int) $row->customer_id : null,
                        'cart_id' => isset($row->cart_id) ? (int) $row->cart_id : null,
                        'tax_exempt' => $row->tax_exempt ?? null,
                        'completed_at' => $row->completed_at ?? null,
                        'refunded_at' => $row->refunded_at ?? null,
                        'refund_status' => $isFullyRefunded ? 'full' : ($refunds !== [] ? 'partial' : 'none'),
                        'totals' => $totals + $refundTotals,
                        'payments' => array_map(self::payment(...), $sale->payments),
                        'taxes' => array_map(self::tax(...), $sale->taxes),
                        'discounts' => array_map(self::discount(...), $sale->discounts),
                        'refunds' => $refundSummary,
                        'addon_pricing' => 'recalculated from pos_settings at import time; BrushCrazy stores add-on names only',
                    ],
                ],
            ],
            'items' => array_map(
                fn (object $item) => self::item($item, $pricer),
                array_values($sale->items)
            ),
        ];
    }

    /**
     * An open ticket (`completed_at` NULL) is a cart in progress, not a sale: BrushCrazy's reports
     * all filter on `completed()` and date by `completed_at`. A deleted sale is an abandoned or
     * failed one. Mirrors are netted into their parent.
     */
    public static function isImportable(SaleRows $sale): bool
    {
        return ! $sale->isMirror()
            && ($sale->sale->deleted_at ?? null) === null
            && ($sale->sale->completed_at ?? null) !== null;
    }

    /**
     * `item_discount_cents` is informational: line discounts are already inside the subtotal.
     *
     * @return array{subtotal_cents: int, discount_cents: int, item_discount_cents: int, tax_cents: int, total_cents: int, paid_cents: int, balance_cents: int}
     */
    public static function totals(SaleRows $sale, AddonPricer $pricer): array
    {
        $subtotal = 0;
        $itemDiscount = 0;

        foreach ($sale->items as $item) {
            if (($item->refunded_at ?? null) === null) {
                $line = self::itemLine($item, $pricer);
                $subtotal += $line['total_cents'];
                $itemDiscount += $line['giveaway'] === null ? $line['discount_cents'] * $line['quantity'] : 0;
            }
        }

        $discount = self::sumCents($sale->discounts);
        // `isTaxExempt()` is a null check: the column holds the exemption reason, not a flag.
        $tax = ($sale->sale->tax_exempt ?? null) !== null ? 0 : self::sumCents($sale->taxes);
        $total = $subtotal - $discount + $tax;
        $paid = self::sumCents($sale->payments);

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'item_discount_cents' => $itemDiscount,
            'tax_cents' => $tax,
            'total_cents' => $total,
            'paid_cents' => $paid,
            'balance_cents' => $total - $paid,
        ];
    }

    /**
     * The total is the unit figure times the quantity, so `unit_cents × quantity` is always exactly
     * `total_cents` — OrderItem has no line-total column and stores the unit price at 2 decimals.
     *
     * @return array{price_cents: int, addons: array<int, array{name: string, cents: int}>, addon_cents: int, discount_cents: int, quantity: int, unit_cents: int, subtotal_cents: int, total_cents: int, giveaway: array<mixed>|null}
     */
    public static function itemLine(object $item, AddonPricer $pricer): array
    {
        $price = Money::fromRaw($item->price ?? null)->cents;
        $addons = $pricer->breakdown($item);
        $addonCents = array_sum(array_column($addons, 'cents'));
        $discount = self::itemDiscountCents($item);
        $quantity = (int) ($item->quantity ?? 0);
        $unit = $price + $addonCents - $discount;
        $giveaway = self::giveaway($item);

        return [
            'price_cents' => $price,
            'addons' => $addons,
            'addon_cents' => $addonCents,
            'discount_cents' => $discount,
            'quantity' => $quantity,
            'unit_cents' => $giveaway !== null ? 0 : $unit,
            'subtotal_cents' => $unit * $quantity,
            'total_cents' => $giveaway !== null ? 0 : $unit * $quantity,
            'giveaway' => $giveaway,
        ];
    }

    /**
     * `SaleItem::discountAmount()` only honours a discount that has a name and a money-shaped
     * amount; anything else — including the default `{"type":"fixed","amount":{...}}` with no
     * name — is no discount at all.
     */
    public static function itemDiscountCents(object $item): int
    {
        $discount = Str::jsonToArray($item->discount ?? null);

        if (! is_array($discount) || empty($discount['name']) || empty($discount['amount']) || ! is_array($discount['amount'])) {
            return 0;
        }

        return Money::fromRaw($discount['amount'])->cents;
    }

    /**
     * The column is malformed at the source (`{"type":"Cause","0":"reason"}`), but it still
     * decodes; BrushCrazy only asks whether `type` is set.
     *
     * @return array<mixed>|null
     */
    public static function giveaway(object $item): ?array
    {
        $giveaway = Str::jsonToArray($item->giveaway ?? null);

        return is_array($giveaway) && ! empty($giveaway['type']) ? $giveaway : null;
    }

    /**
     * @return array{sale_item_id: int, product_id: int|null, kind: SaleLineKindEnum, attributes: array<string, mixed>}
     */
    protected static function item(object $item, AddonPricer $pricer): array
    {
        $line = self::itemLine($item, $pricer);
        $kind = SaleLineKindEnum::classify($item);
        $refundedAt = $item->refunded_at ?? null;
        $sourceMetadata = Str::jsonToArray($item->metadata ?? null);
        $sourceMetadata = is_array($sourceMetadata) ? $sourceMetadata : [];
        $unitDollars = Money::toDollars($line['unit_cents']);

        $metadata = array_filter(
            [
                'calendarable' => $kind === SaleLineKindEnum::CLASS_SEAT ? self::calendarableKey($item) : null,
                'brushcrazy' => array_filter(
                    [
                        'sale_item_id' => (int) $item->id,
                        'kind' => $kind->value,
                        'product_id' => isset($item->product_id) ? (int) $item->product_id : null,
                        'category_name' => $item->category_name ?? null,
                        'painting_id' => $kind === SaleLineKindEnum::PAINTING && isset($item->option_id) ? (int) $item->option_id : null,
                        'first_painting_id' => isset($item->first_option_id) ? (int) $item->first_option_id : null,
                        'second_painting_id' => isset($item->second_option_id) ? (int) $item->second_option_id : null,
                        'gift_card_number' => $kind === SaleLineKindEnum::GIFT_CARD ? ($sourceMetadata['number'] ?? null) : null,
                        'price_cents' => $line['price_cents'],
                        'addons' => $line['addons'] !== [] ? $line['addons'] : null,
                        'discount_cents' => $line['discount_cents'] !== 0 ? $line['discount_cents'] : null,
                        'total_cents' => $line['total_cents'],
                        'giveaway' => $line['giveaway'],
                        'taxable' => (bool) ($item->taxable ?? true),
                        'refunded_at' => $refundedAt,
                        'source_metadata' => $sourceMetadata !== [] ? $sourceMetadata : null,
                    ],
                    fn ($value) => $value !== null
                ),
            ],
            fn ($value) => $value !== null
        );

        return [
            'sale_item_id' => (int) $item->id,
            'product_id' => isset($item->product_id) ? (int) $item->product_id : null,
            'kind' => $kind,
            'attributes' => [
                'product_name' => (string) ($item->product_name ?? $kind->label()),
                'quantity' => $line['quantity'],
                'unit_price_gross_amount' => $unitDollars,
                'unit_price_net_amount' => $unitDollars,
                'currency' => 'USD',
                // A refunded line is kept for the record but hidden from `Order::items()`, which is
                // what keeps the visible lines summing to the order total, as BrushCrazy's do.
                'is_public' => $refundedAt === null,
                'is_deleted' => false,
                'metadata' => $metadata,
                'created_at' => $item->created_at ?? null,
            ],
        ];
    }

    /** The exact shape `registrations.calendarable_type`/`calendarable_id` stores. */
    protected static function calendarableKey(object $item): string
    {
        return CalendarableTypeEnum::from((string) $item->option_type)->value . ':' . (int) $item->option_id;
    }

    /**
     * @param  array<int, SaleRows>  $refunds
     * @return array<int, array<string, mixed>>
     */
    protected static function refundSummary(array $refunds, AddonPricer $pricer): array
    {
        $summary = [];

        foreach ($refunds as $refund) {
            $summary[] = [
                'sale_id' => $refund->id(),
                'refunded_at' => $refund->sale->refunded_at ?? null,
                'kind' => self::isAmountRefund($refund) ? 'amount' : 'item',
                // The receipt's own test for "via Studio Credit": the credit branch never books a payment.
                'via' => $refund->payments === [] ? 'credit' : 'payment',
                // `Sale::total()` of the mirror, which skips lines with `refunded_at`. The mirror line
                // is replicated before the original is stamped, so it only carries a stamp when an
                // already-refunded line was refunded again — and then the source moved no money
                // either (`$refundAmountCents <= 0`).
                'total_cents' => self::totals($refund, $pricer)['total_cents'],
                'lines' => array_map(
                    fn (object $item) => [
                        'product_name' => $item->product_name ?? null,
                        'category_name' => $item->category_name ?? null,
                        'quantity' => (int) ($item->quantity ?? 0),
                        'total_cents' => self::itemLine($item, $pricer)['total_cents'],
                    ],
                    array_values($refund->items)
                ),
                'payments' => array_map(self::payment(...), $refund->payments),
            ];
        }

        return $summary;
    }

    /**
     * Mirrors `Sale::amountRefunded()` / `creditRefunded()` / `balanceAfterRefunds()`.
     * `net_cents` is what the sale finally kept: an item refund already left the total, an amount
     * refund did not, and a full refund kept nothing.
     *
     * @param  array{total_cents: int, balance_cents: int}  $totals
     * @param  array<int, array<string, mixed>>  $refundSummary
     * @return array<string, int|string|null>
     */
    protected static function refundTotals(SaleRows $sale, array $totals, array $refundSummary): array
    {
        $isFullyRefunded = ($sale->sale->refunded_at ?? null) !== null;
        $fullRefund = $isFullyRefunded
            ? self::fullRefund($sale, $refundSummary, $totals['total_cents'])
            : ['cents' => 0, 'via' => null];
        $amountRefunded = 0;
        $itemRefunded = 0;
        $creditRefunded = 0;

        foreach ($refundSummary as $refund) {
            if ($refund['kind'] === 'amount') {
                $amountRefunded += $refund['total_cents'];

                continue;
            }

            $itemRefunded += $refund['total_cents'];

            if ($refund['via'] === 'credit') {
                $creditRefunded += $refund['total_cents'];
            }
        }

        return [
            'amount_refunded_cents' => $amountRefunded,
            'item_refunded_cents' => $itemRefunded,
            'credit_refunded_cents' => $creditRefunded,
            'full_refund_cents' => $fullRefund['cents'],
            'full_refund_via' => $fullRefund['via'],
            'refunded_cents' => $amountRefunded + $itemRefunded + $fullRefund['cents'],
            'balance_after_refunds_cents' => $totals['balance_cents'] - $amountRefunded + $creditRefunded,
            'net_cents' => $isFullyRefunded ? 0 : $totals['total_cents'] - $amountRefunded,
        ];
    }

    /**
     * What the full refund itself handed back, which is not the total once a partial refund ran:
     * - to the payment method (SaleRefundController): every completed payment no earlier refund had
     *   stamped, at its current amount — a card partial refund already decremented it, a cash one
     *   stamped it. Those are the payments stamped after the last partial refund, since no partial
     *   refund is accepted on a refunded sale.
     * - to studio credit: the payments are never touched and the credit is `Sale::total()`.
     * A payment-path refund of a sale with nothing left to refund reads as credit; the source keeps
     * no other trace of which button was pressed.
     *
     * @param  array<int, array<string, mixed>>  $refundSummary
     * @return array{cents: int, via: string}
     */
    protected static function fullRefund(SaleRows $sale, array $refundSummary, int $totalCents): array
    {
        $payments = self::fullRefundPayments($sale, array_map(fn (array $refund) => $refund['refunded_at'], $refundSummary));

        if ($payments === []) {
            return ['cents' => $totalCents, 'via' => 'credit'];
        }

        return ['cents' => self::sumCents($payments), 'via' => 'payment'];
    }

    /**
     * The payments a full refund handed back: completed ones stamped after the last partial refund.
     *
     * @param  array<int, string|null>  $partialRefundTimes
     * @return array<int, object>
     */
    protected static function fullRefundPayments(SaleRows $sale, array $partialRefundTimes): array
    {
        $partialRefundTimes = array_filter($partialRefundTimes);
        $lastPartial = $partialRefundTimes !== [] ? (string) max($partialRefundTimes) : null;

        return array_values(array_filter($sale->payments, function (object $payment) use ($lastPartial) {
            $refundedAt = $payment->refunded_at ?? null;

            if ($refundedAt === null || ($payment->completed_at ?? null) === null) {
                return false;
            }

            return $lastPartial === null || (string) $refundedAt > $lastPartial;
        }));
    }

    /**
     * Money BrushCrazy handed back out of the payment the Kanvas site charged (type `Kanvas`),
     * keyed by a refund identity that reads the same on every re-sync:
     * - `sale:{mirror sale id}` for an item or amount refund: its Kanvas payment rows' sum;
     * - `full:{payment id}` for each Kanvas payment a full refund stamped, at its current amount
     *   (a partial refund already decremented it).
     * A refund to studio credit books no payment row, so it never shows up here. A refund Kanvas
     * itself pushed (POST /api/kanvas/refunds) carries BrushCrazy's marker on its payment rows, so
     * the mirror recognises it instead of refunding the card a second time.
     *
     * @param  array<int, SaleRows>  $refunds
     * @return array<string, array{kind: string, cents: int, refunded_at: string|null, kanvas_refund_id: string|null, from_kanvas: bool}>
     */
    public static function kanvasRefundMoves(SaleRows $sale, array $refunds): array
    {
        $moves = [];

        foreach ($refunds as $refund) {
            $payments = self::kanvasPayments($refund->payments);

            if ($payments !== []) {
                $moves['sale:' . $refund->id()] = self::refundMove(
                    self::isAmountRefund($refund) ? 'amount' : 'item',
                    $payments,
                    $refund->sale->refunded_at ?? null
                );
            }
        }

        if (($sale->sale->refunded_at ?? null) !== null) {
            $partialTimes = array_map(fn (SaleRows $refund) => $refund->sale->refunded_at ?? null, $refunds);

            foreach (self::kanvasPayments(self::fullRefundPayments($sale, $partialTimes)) as $payment) {
                $moves['full:' . (int) $payment->id] = self::refundMove('full', [$payment], $payment->refunded_at ?? null);
            }
        }

        return $moves;
    }

    /**
     * @param  array<int, object>  $payments
     * @return array<int, object>
     */
    protected static function kanvasPayments(array $payments): array
    {
        return array_values(array_filter(
            $payments,
            fn (object $payment) => ($payment->type ?? null) === self::KANVAS_PAYMENT_TYPE && ($payment->completed_at ?? null) !== null
        ));
    }

    /**
     * @param  array<int, object>  $payments
     * @return array{kind: string, cents: int, refunded_at: string|null, kanvas_refund_id: string|null, from_kanvas: bool}
     */
    protected static function refundMove(string $kind, array $payments, mixed $refundedAt): array
    {
        $kanvasRefundId = null;
        $fromKanvas = false;

        foreach ($payments as $payment) {
            $card = (string) ($payment->card_number ?? '');

            if (str_starts_with($card, self::KANVAS_REFUND_CARD_PREFIX)) {
                $kanvasRefundId = substr($card, strlen(self::KANVAS_REFUND_CARD_PREFIX));
                $fromKanvas = true;
            }

            if (($payment->approval_code ?? null) === self::KANVAS_REFUND_APPROVAL_CODE) {
                $fromKanvas = true;
            }
        }

        return [
            'kind' => $kind,
            'cents' => self::sumCents($payments),
            'refunded_at' => $refundedAt !== null ? (string) $refundedAt : null,
            'kanvas_refund_id' => $kanvasRefundId,
            'from_kanvas' => $fromKanvas,
        ];
    }

    protected static function isAmountRefund(SaleRows $refund): bool
    {
        return $refund->items !== []
            && array_filter($refund->items, fn (object $item) => ($item->category_name ?? null) !== 'Refund') === [];
    }

    /**
     * Souk has no partially-paid status; its own SyncPayablePaymentStatusAction never marks an
     * underpaid order PAID, which leaves it PENDING. The balance is the one BrushCrazy prints after
     * refunds, so an amount refund does not read as money still owed.
     */
    protected static function paymentStatus(bool $isFullyRefunded, int $balanceAfterRefundsCents, int $toleranceCents): PaymentStatusEnum
    {
        return match (true) {
            $isFullyRefunded => PaymentStatusEnum::REVERSED,
            $balanceAfterRefundsCents > $toleranceCents => PaymentStatusEnum::PENDING,
            default => PaymentStatusEnum::PAID,
        };
    }

    /**
     * HandlesPartialRefundPayments splits a refund across the sale's payments and rounds each share
     * on its own, so the payments can be decremented by up to half a cent each more than the line
     * that left the total. That leaves a paid sale "owing" a cent or two that nobody owes.
     *
     * @param  array<int, array<string, mixed>>  $refundSummary
     */
    protected static function refundRoundingCents(SaleRows $sale, array $refundSummary): int
    {
        $paymentRefunds = count(array_filter($refundSummary, fn (array $refund) => $refund['via'] === 'payment'));

        return count($sale->payments) * $paymentRefunds;
    }

    /**
     * A credit card number is cut to its last four. Gift, cause and customer cards keep theirs:
     * there it is the stored-value card's own number, which is what reconciles the balance.
     *
     * @return array<string, mixed>
     */
    protected static function payment(object $payment): array
    {
        $type = (string) $payment->type;
        $card = Str::trimToNull($payment->card_number ?? null);

        if ($card !== null && in_array($type, ['CreditCard', 'Vault'], true)) {
            $card = Str::trimToNull(substr((string) preg_replace('/\D/', '', $card), -4));
        }

        return [
            'id' => (int) $payment->id,
            'type' => $type,
            'name' => $payment->name ?? null,
            'amount_cents' => Money::fromRaw($payment->amount ?? null)->cents,
            'card' => $card,
            'approval_code' => $payment->approval_code ?? null,
            'transaction_id' => $payment->transaction_id ?? null,
            'completed_at' => $payment->completed_at ?? null,
            'refunded_at' => $payment->refunded_at ?? null,
        ];
    }

    /**
     * `rate` is a percent as a string ("7.5" is 7.5%) and is informational only: BrushCrazy taxes
     * the whole sale, often with two jurisdictions stacked, and stores the computed amount. That is
     * why OrderItem.tax_rate stays null — no single per-item rate exists to put there.
     *
     * @return array<string, mixed>
     */
    protected static function tax(object $tax): array
    {
        return [
            'name' => $tax->name ?? null,
            'rate' => $tax->rate ?? null,
            'amount_cents' => Money::fromRaw($tax->amount ?? null)->cents,
        ];
    }

    /**
     * A percent discount is converted to money when it is applied (`basis` stays "percent"), so
     * `amount` is normally cents. A row never converted still holds `{"percent": N}` in hundredths
     * of a percent — the source's Sale::discountAmount() reads that as zero, and so does this.
     *
     * @return array<string, mixed>
     */
    protected static function discount(object $discount): array
    {
        $amount = Str::jsonToArray($discount->amount ?? null);

        return array_filter(
            [
                'name' => $discount->name ?? null,
                'type' => $discount->type ?? null,
                'basis' => $discount->basis ?? null,
                'amount_cents' => Money::fromRaw($discount->amount ?? null)->cents,
                'percent' => is_array($amount) && array_key_exists('percent', $amount) ? (float) $amount['percent'] / 100 : null,
                'option_type' => $discount->option_type ?? null,
                'option_id' => isset($discount->option_id) ? (int) $discount->option_id : null,
            ],
            fn ($value) => $value !== null
        );
    }

    /**
     * @param  array<int, object>  $discounts
     */
    protected static function discountName(array $discounts): ?string
    {
        $names = array_filter(array_map(fn (object $discount) => Str::trimToNull($discount->name ?? null), $discounts));

        return $names !== [] ? mb_substr(implode(', ', $names), 0, 255) : null;
    }

    /**
     * @param  array<int, object>  $rows
     */
    protected static function sumCents(array $rows): int
    {
        return array_sum(array_map(fn (object $row) => Money::fromRaw($row->amount ?? null)->cents, $rows));
    }
}
