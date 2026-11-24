<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;

/**
 * Which refunds of a site order have moved money, and who moved it. Lives in the order's
 * `private_metadata.brushcrazy.refunds` (never `metadata`: the site compares that byte for byte),
 * keyed by a refund identity that reads the same on every sync:
 *
 * - `sale:{mirror sale id}` / `full:{payment id}` — a refund staff booked in BrushCrazy
 *   (SaleMapper::kanvasRefundMoves()). Origin `brushcrazy`: Kanvas owes the card that money, so it
 *   goes pending → processing → refunded | failed | manual | skipped.
 * - `kanvas:{payment refund uuid}` — a refund made in Kanvas and pushed to BrushCrazy. When the
 *   mirror reads it back, its BrushCrazy identity is recorded as `kanvas`: no money moves again.
 * - `reverted:{identity}` — BrushCrazy undid a refund Kanvas already paid out. A card refund
 *   cannot be taken back, so it is only flagged `manual`.
 *
 * Every write re-reads the order under a row lock: the mirror and the refund job both write here.
 */
final class RefundLedger
{
    public const string PENDING = 'pending';
    public const string PROCESSING = 'processing';
    public const string REFUNDED = 'refunded';
    public const string FAILED = 'failed';
    public const string MANUAL = 'manual';
    public const string SKIPPED = 'skipped';
    public const string FROM_KANVAS = 'kanvas';
    public const string PUSHED = 'pushed';

    public const string ORIGIN_BRUSHCRAZY = 'brushcrazy';
    public const string ORIGIN_KANVAS = 'kanvas';

    /** Prefix of the `reason` the refund job hands the processor; Stripe stores it on the PaymentRefund. */
    public const string REASON_PREFIX = 'brushcrazy-refund:';

    /** A job that has held an entry this long without settling it died mid-refund. */
    public const int STALE_PROCESSING_MINUTES = 15;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function of(Order $order): array
    {
        $ledger = $order->private_metadata['brushcrazy']['refunds'] ?? [];

        // Orders synced before the ledger existed hold a plain refund summary list here.
        return is_array($ledger) && ! array_is_list($ledger) ? $ledger : [];
    }

    /**
     * Folds what BrushCrazy shows now into the ledger: new money refunds become pending, refunds
     * Kanvas pushed are recorded as such, and a refund that disappeared is skipped (not paid yet)
     * or flagged (already paid).
     *
     * @param  array<string, array<string, mixed>>  $ledger
     * @param  array<string, array{kind: string, cents: int, refunded_at: string|null, kanvas_refund_id: string|null, from_kanvas: bool}>  $moves
     * @return array<string, array<string, mixed>>
     */
    public static function reconcile(array $ledger, array $moves, string $now): array
    {
        foreach ($moves as $key => $move) {
            if (isset($ledger[$key])) {
                continue;
            }

            $pushed = self::pushedEntryFor($ledger, $move);

            $ledger[$key] = [
                'origin' => $move['from_kanvas'] || $pushed !== null ? self::ORIGIN_KANVAS : self::ORIGIN_BRUSHCRAZY,
                'status' => $move['from_kanvas'] || $pushed !== null ? self::FROM_KANVAS : self::PENDING,
                'kind' => $move['kind'],
                'cents' => $move['cents'],
                'brushcrazy_refunded_at' => $move['refunded_at'],
                'kanvas_refund_id' => $move['kanvas_refund_id'] ?? $pushed,
                'recorded_at' => $now,
            ];
        }

        foreach ($ledger as $key => $entry) {
            if (! self::isBrushCrazyKey($key) || isset($moves[$key])) {
                continue;
            }

            if (($entry['status'] ?? null) === self::PENDING) {
                $ledger[$key]['status'] = self::SKIPPED;
                $ledger[$key]['message'] = 'The refund is no longer in BrushCrazy; nothing was refunded.';

                continue;
            }

            if (in_array($entry['status'] ?? null, [self::REFUNDED, self::PROCESSING], true) && ! isset($ledger['reverted:' . $key])) {
                $ledger['reverted:' . $key] = [
                    'origin' => self::ORIGIN_BRUSHCRAZY,
                    'status' => self::MANUAL,
                    'cents' => (int) ($entry['amount_cents'] ?? $entry['cents'] ?? 0),
                    'message' => 'BrushCrazy reverted a refund Kanvas already returned to the card. Nothing was charged again; settle it with the customer by hand.',
                    'recorded_at' => $now,
                ];
            }
        }

        return $ledger;
    }

    /**
     * @param  array<string, array<string, mixed>>  $ledger
     * @return array<int, string>
     */
    public static function pendingKeys(array $ledger): array
    {
        return array_keys(array_filter($ledger, fn (array $entry) => ($entry['status'] ?? null) === self::PENDING));
    }

    /**
     * Azul and CardNet mark the payment REVERSED without writing a PaymentRefund row, so a REVERSED
     * payment with nothing recorded counts as refunded in full.
     */
    public static function paymentRefundedCents(Payments $payment): int
    {
        $refunded = SiteBooking::cents($payment->getRefundedAmount());

        if ($refunded === 0 && $payment->status === PaymentStatusEnum::REVERSED->value) {
            return SiteBooking::cents($payment->amount);
        }

        return $refunded;
    }

    /**
     * Money the refund job already returned without a Souk PaymentRefund row behind it (processors
     * that refund without writing one), so the cap still counts it.
     *
     * @param  array<string, array<string, mixed>>  $ledger
     */
    public static function refundedWithoutRecordCents(array $ledger, string $exceptKey): int
    {
        $cents = 0;

        foreach ($ledger as $key => $entry) {
            if ($key === $exceptKey || ($entry['origin'] ?? null) !== self::ORIGIN_BRUSHCRAZY) {
                continue;
            }

            if (($entry['payment_refund_ids'] ?? []) === []) {
                $cents += (int) ($entry['amount_cents'] ?? 0);
            }
        }

        return $cents;
    }

    /**
     * A refund the job ran because BrushCrazy refunded it. Pushing it back would book it twice.
     */
    public static function isMirrorRefund(Order $order, PaymentRefund $refund): bool
    {
        if (str_starts_with((string) $refund->reason, self::REASON_PREFIX)) {
            return true;
        }

        $cents = SiteBooking::cents($refund->amount);

        foreach (self::of($order) as $entry) {
            if (($entry['origin'] ?? null) !== self::ORIGIN_BRUSHCRAZY) {
                continue;
            }

            if (in_array((int) $refund->getId(), array_map('intval', $entry['payment_refund_ids'] ?? []), true)) {
                return true;
            }

            if ($refund->processor_refund_id !== null && in_array($refund->processor_refund_id, $entry['processor_references'] ?? [], true)) {
                return true;
            }

            // The job is still between the processor call and settling the entry.
            if (($entry['status'] ?? null) === self::PROCESSING && (int) ($entry['cents'] ?? 0) >= $cents) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $ledger
     */
    public static function hasRefundedFullRefund(array $ledger): bool
    {
        foreach ($ledger as $key => $entry) {
            if (str_starts_with($key, 'full:') && in_array($entry['status'] ?? null, [self::REFUNDED, self::FROM_KANVAS], true)) {
                return true;
            }
        }

        return false;
    }

    public static function wasPushed(Order $order, string $kanvasRefundId): bool
    {
        return isset(self::of($order)['kanvas:' . $kanvasRefundId]);
    }

    /**
     * @param  array<string, mixed>  $response  BrushCrazy's answer to POST /api/kanvas/refunds
     */
    public static function recordPushed(
        Order $order,
        PaymentRefund $refund,
        int $cents,
        bool $full,
        array $response
    ): void {
        self::update($order, function (array $ledger) use ($refund, $cents, $full, $response) {
            $ledger['kanvas:' . $refund->uuid] = [
                'origin' => self::ORIGIN_KANVAS,
                'status' => self::PUSHED,
                'payment_refund_ids' => [(int) $refund->getId()],
                'amount_cents' => $cents,
                'full' => $full,
                'brushcrazy_status' => $response['status'] ?? null,
                'brushcrazy_refund_sale_id' => $response['refund_sale_id'] ?? null,
                'recorded_at' => now()->toIso8601String(),
            ];

            return $ledger;
        });
    }

    /**
     * Applies $change to the ledger of a freshly locked copy of the order and saves it quietly.
     *
     * @param  Closure(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change
     * @return array<string, array<string, mixed>> the ledger as saved
     */
    public static function update(Order $order, Closure $change): array
    {
        return DB::connection('commerce')->transaction(function () use ($order, $change) {
            /** @var Order $locked */
            $locked = Order::withTrashed()->whereKey($order->getId())->lockForUpdate()->firstOrFail();
            $ledger = $change(self::of($locked));

            $privateMetadata = is_array($locked->private_metadata) ? $locked->private_metadata : [];
            $brushcrazy = is_array($privateMetadata['brushcrazy'] ?? null) ? $privateMetadata['brushcrazy'] : [];
            $brushcrazy['refunds'] = $ledger;
            $privateMetadata['brushcrazy'] = $brushcrazy;

            $locked->private_metadata = $privateMetadata;
            $locked->saveQuietly();
            $order->setRawAttributes($locked->getAttributes(), true);

            return $ledger;
        });
    }

    private static function isBrushCrazyKey(string $key): bool
    {
        return str_starts_with($key, 'sale:') || str_starts_with($key, 'full:');
    }

    /**
     * A pushed refund whose BrushCrazy rows lost the marker is still recognised: by its id when the
     * mirror sale names it, or, for a full refund, by a full refund Kanvas pushed.
     *
     * @param  array<string, array<string, mixed>>  $ledger
     * @param  array{kind: string, kanvas_refund_id: string|null}  $move
     */
    private static function pushedEntryFor(array $ledger, array $move): ?string
    {
        if ($move['kanvas_refund_id'] !== null && isset($ledger['kanvas:' . $move['kanvas_refund_id']])) {
            return $move['kanvas_refund_id'];
        }

        if ($move['kind'] !== 'full') {
            return null;
        }

        foreach ($ledger as $key => $entry) {
            if (str_starts_with($key, 'kanvas:') && ! empty($entry['full'])) {
                return substr($key, strlen('kanvas:'));
            }
        }

        return null;
    }
}
