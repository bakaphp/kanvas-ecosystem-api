<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Support\Money;

/**
 * Folds every registration of one calendarable into one participation per buyer — the shape Kanvas
 * keeps (one EventVersionParticipant per buyer per event, with its seats). 5.6% of (calendarable,
 * buyer) pairs hold more than one registration at the source, so this merge is the normal path.
 *
 * The buyer is `sales.customer_id` through `registrations.sale_id`; `registrations.created_by` is
 * the cashier and is never read. Rows with no sale, an orphaned sale_id, no customer, or a customer
 * whose `users` row is gone ($goneBuyerIds) fold into one `guest` participation per calendarable:
 * they all land on the same unknown-guest participant, and Kanvas keeps one row per participant.
 *
 * Input rows are what SyncRegistrationFromBrushCrazyAction::readSource() selects: registrations
 * carry `sale_found`, `buyer_id`, `sale_created_at` and, once BrushCrazy has the column,
 * `kanvas_order_uuid` from a left join on `sales`.
 */
class RegistrationMapper
{
    public const string GUEST = 'guest';

    public const string FLAG_NO_BUYER = 'no_buyer';
    /** The sale names a customer whose `users` row no longer exists. */
    public const string FLAG_BUYER_MISSING = 'buyer_missing';
    public const string FLAG_ORPHAN_SALE = 'orphan_sale';
    public const string FLAG_DEPOSIT_LINE = 'deposit_line';
    public const string FLAG_POSSIBLE_PHANTOM_SEATS = 'possible_phantom_seats';

    private const array NO_NAME = ['', 'no name submitted'];

    /**
     * @param  iterable<object>  $registrations
     * @param  iterable<object>  $attendees
     * @param  iterable<object>  $saleItems  lines whose option is this calendarable
     * @param  array<int, int>  $goneBuyerIds  buyers with no People here and no `users` row at the source
     * @param  string  $timezone  the studio's; BrushCrazy stores timestamps in UTC
     * @return array<string, array{
     *     key: string,
     *     buyer_id: int|null,
     *     seat_count: int,
     *     participation: array<string, mixed>,
     *     created_at: string|null
     * }>
     */
    public static function groups(
        string $calendarableKey,
        iterable $registrations,
        iterable $attendees,
        iterable $saleItems,
        array $goneBuyerIds = [],
        string $timezone = 'UTC'
    ): array {
        $gone = array_fill_keys($goneBuyerIds, true);
        $byBuyer = [];

        foreach ($registrations as $registration) {
            $buyerId = self::buyerId($registration);
            $buyerKey = $buyerId !== null && ! isset($gone[$buyerId]) ? 'u' . $buyerId : self::GUEST;
            $byBuyer[$buyerKey][] = $registration;
        }

        $attendeesByRegistration = [];

        foreach ($attendees as $attendee) {
            if ($attendee->registration_id !== null) {
                $attendeesByRegistration[(int) $attendee->registration_id][] = $attendee;
            }
        }

        $itemsBySale = [];

        foreach ($saleItems as $item) {
            $itemsBySale[(int) $item->sale_id][] = $item;
        }

        ksort($byBuyer);
        $groups = [];

        foreach ($byBuyer as $buyerKey => $rows) {
            $key = $calendarableKey . ':' . $buyerKey;
            $groups[$key] = self::group(
                $key,
                $buyerKey === self::GUEST,
                $rows,
                $attendeesByRegistration,
                $itemsBySale,
                $timezone
            );
        }

        return $groups;
    }

    public static function buyerId(object $registration): ?int
    {
        $buyerId = (int) ($registration->buyer_id ?? 0);

        return ($registration->sale_found ?? null) !== null && $buyerId > 0 ? $buyerId : null;
    }

    /**
     * A sale the site made in Kanvas carries its order's uuid (`sales.kanvas_order_uuid`). Its seats
     * already exist as the site's own participations, one per attendee, so the mirror writes none
     * for it and only follows a refund made in BrushCrazy's admin.
     *
     * @param  iterable<object>  $registrations
     * @return array{0: array<int, object>, 1: array<string, bool>} the registrations to mirror, and
     *     each Kanvas order uuid => whether any of its registrations here is still live
     */
    public static function splitKanvasOrders(iterable $registrations): array
    {
        $mirrored = [];
        $orders = [];

        foreach ($registrations as $registration) {
            $uuid = self::kanvasOrderUuid($registration);

            if ($uuid === null) {
                $mirrored[] = $registration;

                continue;
            }

            $orders[$uuid] = ($orders[$uuid] ?? false) || ($registration->deleted_at ?? null) === null;
        }

        return [$mirrored, $orders];
    }

    public static function kanvasOrderUuid(object $registration): ?string
    {
        if (($registration->sale_found ?? null) === null) {
            return null;
        }

        $uuid = Str::trimmedStringOrNull($registration->kanvas_order_uuid ?? null);

        return $uuid !== null ? strtolower($uuid) : null;
    }

    /**
     * @param  array<int, object>  $registrations
     * @param  array<int, array<int, object>>  $attendeesByRegistration
     * @param  array<int, array<int, object>>  $itemsBySale
     * @return array{key: string, buyer_id: int|null, seat_count: int, participation: array<string, mixed>, created_at: string|null}
     */
    private static function group(
        string $key,
        bool $isGuest,
        array $registrations,
        array $attendeesByRegistration,
        array $itemsBySale,
        string $timezone
    ): array {
        usort($registrations, fn (object $a, object $b) => (int) $a->id <=> (int) $b->id);

        $buyerId = $isGuest ? null : self::buyerId($registrations[0]);
        $flags = [];
        $saleIds = [];
        $saleDates = [];
        $createdAt = [];
        $registrationMeta = [];
        $seats = [];
        $live = false;

        foreach ($registrations as $registration) {
            $isLive = ($registration->deleted_at ?? null) === null;
            $live = $live || $isLive;
            $orphanSale = $registration->sale_id !== null && ($registration->sale_found ?? null) === null;
            $registrationBuyerId = self::buyerId($registration);

            if ($isGuest) {
                $flags[] = $registrationBuyerId === null ? self::FLAG_NO_BUYER : self::FLAG_BUYER_MISSING;
            }

            if ($orphanSale) {
                $flags[] = self::FLAG_ORPHAN_SALE;
            }

            if ($registration->sale_id !== null && ! $orphanSale) {
                $saleIds[(int) $registration->sale_id] = true;
            }

            if (($registration->sale_created_at ?? null) !== null) {
                $saleDates[] = Carbon::parse((string) $registration->sale_created_at, 'UTC')->setTimezone($timezone)->format('Y-m-d');
            }

            if (($registration->created_at ?? null) !== null) {
                $createdAt[] = (string) $registration->created_at;
            }

            $registrationMeta[] = [
                'id' => (int) $registration->id,
                'sale_id' => $registration->sale_id !== null ? (int) $registration->sale_id : null,
                'buyer_id' => $registrationBuyerId,
                'notes' => Str::trimToNull($registration->notes ?? null),
                'created_at' => $registration->created_at ?? null,
                'deleted_at' => $registration->deleted_at ?? null,
            ];

            foreach ($attendeesByRegistration[(int) $registration->id] ?? [] as $attendee) {
                $seats[] = self::seat($attendee, $isLive);
            }
        }

        usort($seats, fn (array $a, array $b) => $a['attendee_id'] <=> $b['attendee_id']);
        $seatCount = count(array_filter($seats, fn (array $seat) => $seat['counted']));

        $items = [];

        foreach (array_keys($saleIds) as $saleId) {
            foreach ($itemsBySale[$saleId] ?? [] as $item) {
                $items[] = self::saleItem($item);
            }
        }

        usort($items, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        $pricing = self::pricing($items, $saleIds !== []);

        if ($pricing['deposit']) {
            $flags[] = self::FLAG_DEPOSIT_LINE;

            // The source reconciler adds one attendee per non-refunded line quantity, deposit
            // lines included, so a deposit can leave seats nobody paid for. Flagged, not fixed:
            // whether a seat is real is the studio's call.
            if ($seatCount > $pricing['paid_seats']) {
                $flags[] = self::FLAG_POSSIBLE_PHANTOM_SEATS;
            }
        }

        sort($saleDates);
        sort($createdAt);

        return [
            'key' => $key,
            'buyer_id' => $buyerId,
            'seat_count' => $seatCount,
            'created_at' => $createdAt[0] ?? null,
            'participation' => [
                'ticket_price' => $pricing['ticket_price'],
                'discount' => $pricing['discount'],
                'payment_status' => $pricing['payment_status'],
                'invoice_date' => $saleDates[0] ?? null,
                'is_deleted' => ! $live,
                'metadata' => [
                    'brushcrazy' => [
                        'participation_key' => $key,
                        'buyer_id' => $buyerId,
                        'seat_count' => $seatCount,
                        'flags' => array_values(array_unique($flags)),
                        'registrations' => $registrationMeta,
                        'seats' => $seats,
                        'sale_items' => $items,
                    ],
                ],
            ],
        ];
    }

    /**
     * A live attendee under a trashed registration is not a seat: RemoveRegistrations soft-deletes
     * the registration through the query builder and leaves its attendees untouched.
     *
     * @return array<string, mixed>
     */
    private static function seat(object $attendee, bool $registrationIsLive): array
    {
        $name = trim((string) ($attendee->name ?? ''));
        $deletedAt = $attendee->deleted_at ?? null;

        return [
            'attendee_id' => (int) $attendee->id,
            'registration_id' => (int) $attendee->registration_id,
            'name' => in_array(strtolower($name), self::NO_NAME, true) ? null : $name,
            'checked_in_at' => $attendee->checked_in_at ?? null,
            'no_show' => (bool) ($attendee->no_show ?? false),
            'deleted_at' => $deletedAt,
            'counted' => $registrationIsLive && $deletedAt === null,
        ];
    }

    /**
     * A giveaway line is free at the source (SaleItem::total() is zero), so its whole price is the
     * discount.
     *
     * @return array<string, mixed>
     */
    private static function saleItem(object $item): array
    {
        $priceCents = Money::fromRaw($item->price ?? null)->cents;
        $discountCents = SaleMapper::giveaway($item) !== null
            ? $priceCents
            : min($priceCents, SaleMapper::itemDiscountCents($item));

        return [
            'id' => (int) $item->id,
            'sale_id' => (int) $item->sale_id,
            'product_name' => $item->product_name ?? null,
            'quantity' => (int) ($item->quantity ?? 1),
            'price_cents' => $priceCents,
            'discount_cents' => $discountCents,
            'kind' => SaleLineKindEnum::classify($item)->value,
            'refunded_at' => $item->refunded_at ?? null,
        ];
    }

    /**
     * `ticket_price` / `discount` are the totals over every seat line of the participation (price ×
     * quantity), not a unit price: one participation covers several seats and possibly several
     * sales. Refunded lines stay in the totals — `payment_status` says they were refunded. Only seat
     * lines count: a deposit, tip, gift card or refund line can carry the class as its option too,
     * and SaleLineKindEnum is the classifier the order import uses for the same lines.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{ticket_price: float, discount: float, payment_status: string|null, paid_seats: int, deposit: bool}
     */
    private static function pricing(array $items, bool $hasSale): array
    {
        $seatLines = array_filter($items, fn (array $item) => $item['kind'] === SaleLineKindEnum::CLASS_SEAT->value);
        $priceCents = 0;
        $discountCents = 0;
        $paidSeats = 0;
        $refunded = 0;

        foreach ($seatLines as $line) {
            $priceCents += $line['price_cents'] * $line['quantity'];
            $discountCents += $line['discount_cents'] * $line['quantity'];

            if ($line['refunded_at'] !== null) {
                $refunded++;
            } else {
                $paidSeats += $line['quantity'];
            }
        }

        $status = match (true) {
            ! $hasSale || $seatLines === [] => null,
            $refunded === count($seatLines) => 'refunded',
            $refunded > 0 => 'partially_refunded',
            default => 'paid',
        };

        return [
            'ticket_price' => round($priceCents / 100, 2),
            'discount' => round($discountCents / 100, 2),
            'payment_status' => $status,
            'paid_seats' => $paidSeats,
            'deposit' => in_array(SaleLineKindEnum::EVENT_DEPOSIT->value, array_column($items, 'kind'), true),
        ];
    }
}
