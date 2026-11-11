<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Illuminate\Support\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Actions\SyncSaleFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

/**
 * A booking the public site made, read back from the Kanvas rows it leaves behind: the draft order
 * (`brushcrazy.event-order.v1` metadata, `bc-event:` reference), one EventVersionParticipant per
 * attendee carrying `metadata.order_uuid`, and the EventVersion whose BRUSHCRAZY_CALENDARABLE_KEY
 * names the BrushCrazy class. Shared by the paid-order and the $0 RSVP write-back.
 */
final class SiteBooking
{
    public const string ORDER_SCHEMA = 'brushcrazy.event-order.v1';
    public const string RESERVATION_SCHEMA = 'brushcrazy.event-reservation.v1';
    public const string REFERENCE_PREFIX = 'bc-event:';
    public const string STATE_CONFIRMED = 'confirmed';

    private function __construct(
        public readonly Order $order,
        public readonly EventVersion $eventVersion,
        public readonly CalendarableTypeEnum $calendarableType,
        public readonly int $calendarableId,
        public readonly int $studioId,
    ) {
    }

    public static function isSiteOrder(Order $order): bool
    {
        return ($order->getMetadata('schema') ?? null) === self::ORDER_SCHEMA
            || str_starts_with((string) $order->reference, self::REFERENCE_PREFIX);
    }

    /**
     * Rows the mirror created carry the BrushCrazy sale id without the kanvas origin; pushing them
     * would book the same sale twice in BrushCrazy.
     */
    public static function isMirroredOrder(Order $order): bool
    {
        return self::hasValue($order->get(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value))
            && $order->get(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value) !== SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS;
    }

    public static function wasPushed(Order $order): bool
    {
        return self::hasValue($order->get(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value))
            && $order->get(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value) === SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS;
    }

    /**
     * Links the order (and its buyer) to what BrushCrazy booked. Workflows are off for the writes:
     * a custom field set fires `create-custom-field`, and the order is what triggered this push.
     */
    public static function stampPushed(Order $order, array $response): void
    {
        if (! empty($response['sale_id'])) {
            $order->disableWorkflows();
            $order->set(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value, (int) $response['sale_id']);
            $order->set(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value, SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS);
        }

        if ($order->people !== null && ! empty($response['user_id'])) {
            self::stampUser($order->people, (int) $response['user_id']);
        }
    }

    /**
     * A People the mirror already linked keeps its link; only an unlinked one is stamped.
     */
    public static function stampUser(People $people, int $bcUserId): void
    {
        if (self::hasValue($people->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value))) {
            return;
        }

        $people->disableWorkflows();
        $people->set(CustomFieldEnum::BRUSHCRAZY_USER_ID->value, $bcUserId);
        $people->set(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value, SyncSaleFromBrushCrazyAction::ORIGIN_KANVAS);
    }

    public static function studioId(Companies $company): ?int
    {
        $studioId = $company->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_ID->value);

        return is_numeric($studioId) ? (int) $studioId : null;
    }

    /**
     * Null with the reason when the order cannot be booked in BrushCrazy; callers turn that into a
     * failed run, since retrying will not make the missing piece appear.
     *
     * @return array{0: ?self, 1: ?string}
     */
    public static function resolve(Order $order): array
    {
        $studioId = self::studioId($order->company);

        if ($studioId === null) {
            return [null, 'The order company has no BRUSHCRAZY_STUDIO_ID.'];
        }

        $eventVersion = self::eventVersionFor($order);

        if ($eventVersion === null) {
            return [null, 'No EventVersion found for the order (metadata.event_version_id or ticket variant).'];
        }

        $parsed = CalendarableTypeEnum::parseKey((string) $eventVersion->get(CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY->value));

        if ($parsed === null) {
            return [null, "EventVersion {$eventVersion->getId()} has no valid BRUSHCRAZY_CALENDARABLE_KEY."];
        }

        [$type, $id] = $parsed;

        return [new self($order, $eventVersion, $type, $id, $studioId), null];
    }

    public static function findOrder(EventVersion $eventVersion, ?string $orderUuid): ?Order
    {
        if ($orderUuid === null || $orderUuid === '') {
            return null;
        }

        return Order::query()
            ->where('uuid', $orderUuid)
            ->where('apps_id', $eventVersion->apps_id)
            ->where('companies_id', $eventVersion->companies_id)
            ->first();
    }

    /**
     * Every seat line points at the same class: one site order books one EventVersion.
     *
     * @return list<array{kind: string, calendarable_type: string, calendarable_id: int, quantity: int, unit_cents: int, attendees: list<array{name: string}>}>
     */
    public function seatLines(): array
    {
        $attendeesByVariant = $this->attendeesByVariant();
        $lines = [];

        /** @var OrderItem $item */
        foreach ($this->order->items()->get() as $item) {
            $quantity = (int) $item->quantity;

            if ($quantity < 1) {
                continue;
            }

            $names = $attendeesByVariant[(string) $item->variant_id] ?? [];

            $lines[] = [
                'kind' => 'seat',
                'calendarable_type' => $this->calendarableType->value,
                'calendarable_id' => $this->calendarableId,
                'quantity' => $quantity,
                'unit_cents' => self::cents($item->unit_price_gross_amount ?? $item->unit_price_net_amount),
                'attendees' => array_map(
                    fn (string $name) => ['name' => $name],
                    array_slice($names, 0, $quantity)
                ),
            ];
        }

        return $lines;
    }

    /**
     * The site's per-attendee EVPs for this order, live ones only, in attendee order.
     *
     * @return Collection<int, EventVersionParticipant>
     */
    public function participants(): Collection
    {
        return EventVersionParticipant::query()
            ->where('event_version_id', $this->eventVersion->getId())
            ->where('metadata->order_uuid', $this->order->uuid)
            ->with('participant.people')
            ->get()
            ->sortBy(fn (EventVersionParticipant $evp) => (int) ($evp->metadata['attendee_index'] ?? PHP_INT_MAX))
            ->values();
    }

    /**
     * @return list<array{name: string}>
     */
    public function attendees(): array
    {
        return array_map(
            fn (string $name) => ['name' => $name],
            array_merge(...array_values($this->attendeesByVariant()))
        );
    }

    /**
     * @return array{email: string, first_name: string, last_name: string, phone: ?string}
     */
    public function customer(): array
    {
        return self::customerFrom(
            $this->order->people,
            $this->order->user_email,
            $this->order->user_phone
        );
    }

    /**
     * @return array{email: string, first_name: string, last_name: string, phone: ?string}
     */
    public static function customerFrom(?People $people, ?string $email, ?string $phone): array
    {
        $email ??= $people?->getEmails()->first()?->value;
        $phone ??= $people?->getPhones()->first()?->value;
        $firstName = trim((string) $people?->firstname);
        $lastName = trim((string) $people?->lastname);

        if ($firstName === '' && $people !== null) {
            [$firstName, $lastName] = array_pad(explode(' ', trim($people->getName()), 2), 2, '');
        }

        return [
            'email' => strtolower(trim((string) $email)),
            'first_name' => $firstName,
            'last_name' => trim((string) $lastName),
            'phone' => $phone !== null && trim($phone) !== '' ? trim($phone) : null,
        ];
    }

    public static function cents(mixed $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false;
    }

    private static function eventVersionFor(Order $order): ?EventVersion
    {
        $eventVersionId = $order->getMetadata('event_version_id');

        if (is_numeric($eventVersionId)) {
            $eventVersion = EventVersion::query()
                ->where('id', (int) $eventVersionId)
                ->where('companies_id', $order->companies_id)
                ->first();

            if ($eventVersion !== null) {
                return $eventVersion;
            }
        }

        $variantId = $order->items()->value('variant_id');

        if ($variantId === null) {
            return null;
        }

        return EventVersion::query()
            ->where('companies_id', $order->companies_id)
            ->whereJsonContains('metadata->brushcrazy_booking->allowed_variant_ids', (string) $variantId)
            ->first();
    }

    /**
     * Names by the variant each attendee picked. The EVPs are the source of truth; the order's own
     * metadata.attendees is the fallback for an order whose EVPs were already released.
     *
     * @return array<string, list<string>>
     */
    private function attendeesByVariant(): array
    {
        $byVariant = [];

        foreach ($this->participants() as $evp) {
            $name = trim((string) $evp->participant?->people?->getName());

            if ($name !== '') {
                $byVariant[(string) ($evp->metadata['variant_id'] ?? '')][] = $name;
            }
        }

        if ($byVariant !== []) {
            return $byVariant;
        }

        foreach ((array) $this->order->getMetadata('attendees') as $attendee) {
            $name = trim((string) ($attendee['name'] ?? ''));

            if ($name !== '') {
                $byVariant[(string) ($attendee['variant_id'] ?? '')][] = $name;
            }
        }

        return $byVariant;
    }
}
