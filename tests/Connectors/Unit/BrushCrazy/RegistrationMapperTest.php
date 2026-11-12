<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Mappers\RegistrationMapper;
use Tests\TestCase;

final class RegistrationMapperTest extends TestCase
{
    private const string CALENDARABLE = 'lesson:512';

    public function testMergesEveryRegistrationOfOneBuyerIntoOneParticipation(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [
                $this->registration(['id' => 2, 'sale_id' => 20, 'sale_found' => 20, 'buyer_id' => 7]),
                $this->registration(['id' => 1, 'sale_id' => 10, 'sale_found' => 10, 'buyer_id' => 7]),
                $this->registration(['id' => 3, 'sale_id' => 30, 'sale_found' => 30, 'buyer_id' => 8]),
            ],
            [
                $this->attendee(['id' => 100, 'registration_id' => 1]),
                $this->attendee(['id' => 101, 'registration_id' => 2]),
                $this->attendee(['id' => 102, 'registration_id' => 2]),
                $this->attendee(['id' => 103, 'registration_id' => 3]),
            ],
            [],
        );

        $this->assertSame(['lesson:512:u7', 'lesson:512:u8'], array_keys($groups));

        $merged = $groups['lesson:512:u7'];
        $metadata = $merged['participation']['metadata']['brushcrazy'];

        $this->assertSame(7, $merged['buyer_id']);
        $this->assertSame(3, $merged['seat_count']);
        $this->assertSame([1, 2], array_column($metadata['registrations'], 'id'));
        $this->assertSame([100, 101, 102], array_column($metadata['seats'], 'attendee_id'));
        $this->assertSame('2026-01-01', $merged['participation']['invoice_date']);
        $this->assertSame('2025-12-01 10:00:00', $merged['created_at']);
    }

    /** A refund trashes the registration but leaves its attendees live; they are not seats. */
    public function testAttendeesUnderATrashedRegistrationAreNotSeats(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [
                $this->registration(['id' => 1, 'deleted_at' => '2026-02-01 00:00:00']),
                $this->registration(['id' => 2]),
            ],
            [
                $this->attendee(['id' => 100, 'registration_id' => 1]),
                $this->attendee(['id' => 101, 'registration_id' => 2]),
                $this->attendee(['id' => 102, 'registration_id' => 2, 'deleted_at' => '2026-02-02 00:00:00']),
            ],
            [],
        );

        $group = $groups['lesson:512:u7'];
        $seats = array_column($group['participation']['metadata']['brushcrazy']['seats'], 'counted', 'attendee_id');

        $this->assertSame(1, $group['seat_count']);
        $this->assertSame([100 => false, 101 => true, 102 => false], $seats);
        $this->assertFalse($group['participation']['is_deleted']);
    }

    public function testParticipationIsDeletedOnlyWhenEveryRegistrationIs(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [
                $this->registration(['id' => 1, 'deleted_at' => '2026-02-01 00:00:00']),
                $this->registration(['id' => 2, 'deleted_at' => '2026-02-01 00:00:00']),
            ],
            [$this->attendee(['id' => 100, 'registration_id' => 1])],
            [],
        );

        $this->assertTrue($groups['lesson:512:u7']['participation']['is_deleted']);
        $this->assertSame(0, $groups['lesson:512:u7']['seat_count']);
    }

    public function testNoSaleOrphanSaleAndMissingCustomerFoldIntoOneGuestParticipation(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [
                $this->registration(['id' => 1, 'sale_id' => null, 'sale_found' => null, 'buyer_id' => null]),
                $this->registration(['id' => 2, 'sale_id' => 99, 'sale_found' => null, 'buyer_id' => null]),
                $this->registration(['id' => 3, 'sale_id' => 30, 'sale_found' => 30, 'buyer_id' => 0]),
            ],
            [
                $this->attendee(['id' => 100, 'registration_id' => 1]),
                $this->attendee(['id' => 101, 'registration_id' => 2]),
            ],
            [],
        );

        $this->assertSame(['lesson:512:guest'], array_keys($groups));

        $guest = $groups['lesson:512:guest'];
        $flags = $guest['participation']['metadata']['brushcrazy']['flags'];

        $this->assertNull($guest['buyer_id']);
        $this->assertSame(2, $guest['seat_count']);
        $this->assertContains(RegistrationMapper::FLAG_NO_BUYER, $flags);
        $this->assertContains(RegistrationMapper::FLAG_ORPHAN_SALE, $flags);
    }

    public function testBlankAndPlaceholderAttendeeNamesBecomeNull(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [$this->registration(['id' => 1])],
            [
                $this->attendee(['id' => 100, 'registration_id' => 1, 'name' => '']),
                $this->attendee(['id' => 101, 'registration_id' => 1, 'name' => 'No Name Submitted']),
                $this->attendee(['id' => 102, 'registration_id' => 1, 'name' => ' Ana ']),
            ],
            [],
        );

        $names = array_column($groups['lesson:512:u7']['participation']['metadata']['brushcrazy']['seats'], 'name');

        $this->assertSame([null, null, 'Ana'], $names);
    }

    public function testPricingTotalsTheSeatLinesOfTheBuyersSales(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [$this->registration(['id' => 1, 'sale_id' => 10, 'sale_found' => 10])],
            [],
            [
                $this->item(['id' => 1, 'sale_id' => 10, 'quantity' => 2, 'price' => '{"amount":3500,"currency":"USD"}']),
                $this->item([
                    'id' => 2,
                    'sale_id' => 10,
                    'price' => '{"amount":3500,"currency":"USD"}',
                    'discount' => '{"name":"Promo","type":"fixed","amount":{"amount":"500","currency":"USD"},"reason":null}',
                ]),
                $this->item(['id' => 3, 'sale_id' => 99, 'price' => '{"amount":9999,"currency":"USD"}']),
            ],
        );

        $participation = $groups['lesson:512:u7']['participation'];

        $this->assertSame(105.0, $participation['ticket_price']);
        $this->assertSame(5.0, $participation['discount']);
        $this->assertSame('paid', $participation['payment_status']);
        $this->assertSame([1, 2], array_column($participation['metadata']['brushcrazy']['sale_items'], 'id'));
    }

    public function testPaymentStatusFollowsRefundedLines(): void
    {
        $status = fn (array $items) => RegistrationMapper::groups(
            self::CALENDARABLE,
            [$this->registration(['id' => 1, 'sale_id' => 10, 'sale_found' => 10])],
            [],
            $items,
        )['lesson:512:u7']['participation']['payment_status'];

        $refunded = ['refunded_at' => '2026-02-01 00:00:00'];

        $this->assertSame('refunded', $status([$this->item(['id' => 1] + $refunded)]));
        $this->assertSame('partially_refunded', $status([$this->item(['id' => 1] + $refunded), $this->item(['id' => 2])]));
        $this->assertNull($status([]));
    }

    public function testFreeRegistrationWithoutASaleHasNoPaymentStatus(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [$this->registration(['id' => 1, 'sale_id' => null, 'sale_found' => null, 'buyer_id' => null])],
            [],
            [],
        );

        $this->assertNull($groups['lesson:512:guest']['participation']['payment_status']);
        $this->assertSame(0.0, $groups['lesson:512:guest']['participation']['ticket_price']);
    }

    /** The source reconciler seats a deposit line too, so more seats than paid seat lines is suspect. */
    public function testDepositLineIsFlaggedAndExcludedFromThePrice(): void
    {
        $groups = RegistrationMapper::groups(
            'event:40',
            [$this->registration(['id' => 1, 'calendarable_type' => 'event', 'calendarable_id' => 40])],
            [
                $this->attendee(['id' => 100, 'registration_id' => 1]),
                $this->attendee(['id' => 101, 'registration_id' => 1]),
            ],
            [
                $this->item(['id' => 1, 'option_type' => 'event', 'option_id' => 40]),
                $this->item([
                    'id' => 2,
                    'option_type' => 'event',
                    'option_id' => 40,
                    'product_name' => 'Event Deposit',
                    'price' => '{"amount":10000,"currency":"USD"}',
                ]),
            ],
        );

        $participation = $groups['event:40:u7']['participation'];
        $flags = $participation['metadata']['brushcrazy']['flags'];

        $this->assertSame(35.0, $participation['ticket_price']);
        $this->assertContains(RegistrationMapper::FLAG_DEPOSIT_LINE, $flags);
        $this->assertContains(RegistrationMapper::FLAG_POSSIBLE_PHANTOM_SEATS, $flags);
    }

    public function testPartyDepositCategoryIsADeposit(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [$this->registration(['id' => 1])],
            [],
            [
                $this->item(['id' => 1]),
                $this->item(['id' => 2, 'product_name' => 'Party', 'category_name' => 'Party Deposits']),
            ],
        );

        $participation = $groups['lesson:512:u7']['participation'];

        $this->assertSame(35.0, $participation['ticket_price']);
        $this->assertContains(RegistrationMapper::FLAG_DEPOSIT_LINE, $participation['metadata']['brushcrazy']['flags']);
    }

    /** Tips, gift cards and refund lines can carry the class as their option; none of them is a seat. */
    public function testOnlySeatLinesArePriced(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [$this->registration(['id' => 1])],
            [],
            [
                $this->item(['id' => 1]),
                $this->item(['id' => 2, 'product_name' => 'Tip', 'category_name' => 'Tips', 'price' => '{"amount":1000,"currency":"USD"}']),
                $this->item(['id' => 3, 'product_name' => 'Gift Card', 'category_name' => 'Gift Cards', 'price' => '{"amount":5000,"currency":"USD"}']),
                $this->item(['id' => 4, 'product_name' => 'Refund', 'category_name' => 'Refund', 'price' => '{"amount":2000,"currency":"USD"}']),
            ],
        );

        $participation = $groups['lesson:512:u7']['participation'];

        $this->assertSame(35.0, $participation['ticket_price']);
        $this->assertSame('paid', $participation['payment_status']);
        $this->assertNotContains(RegistrationMapper::FLAG_DEPOSIT_LINE, $participation['metadata']['brushcrazy']['flags']);
        $this->assertSame(
            ['class-seat', 'tip', 'gift-card', 'refund'],
            array_column($participation['metadata']['brushcrazy']['sale_items'], 'kind')
        );
    }

    /** 19:00 in Denver on Jan 1 is already Jan 2 in UTC. */
    public function testInvoiceDateIsTheSaleDateInTheStudioTimezone(): void
    {
        $registrations = [$this->registration(['id' => 1, 'sale_created_at' => '2026-01-02 02:00:00'])];

        $denver = RegistrationMapper::groups(
            self::CALENDARABLE,
            $registrations,
            [],
            [],
            timezone: 'America/Denver',
        );
        $utc = RegistrationMapper::groups(
            self::CALENDARABLE,
            $registrations,
            [],
            [],
        );

        $this->assertSame('2026-01-01', $denver['lesson:512:u7']['participation']['invoice_date']);
        $this->assertSame('2026-01-02', $utc['lesson:512:u7']['participation']['invoice_date']);
    }

    /** They all seat on the one unknown guest, which Kanvas holds as one participation per event. */
    public function testBuyersGoneAtTheSourceFoldIntoTheGuestParticipation(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [
                $this->registration(['id' => 1, 'sale_id' => null, 'sale_found' => null, 'buyer_id' => null]),
                $this->registration(['id' => 2, 'sale_id' => 20, 'sale_found' => 20, 'buyer_id' => 8]),
                $this->registration(['id' => 3, 'sale_id' => 30, 'sale_found' => 30, 'buyer_id' => 9]),
                $this->registration(['id' => 4, 'sale_id' => 40, 'sale_found' => 40, 'buyer_id' => 7]),
            ],
            [
                $this->attendee(['id' => 100, 'registration_id' => 1]),
                $this->attendee(['id' => 101, 'registration_id' => 2]),
                $this->attendee(['id' => 102, 'registration_id' => 3]),
                $this->attendee(['id' => 103, 'registration_id' => 4]),
            ],
            [],
            goneBuyerIds: [8, 9],
        );

        $this->assertSame(['lesson:512:guest', 'lesson:512:u7'], array_keys($groups));

        $guest = $groups['lesson:512:guest'];
        $metadata = $guest['participation']['metadata']['brushcrazy'];

        $this->assertNull($guest['buyer_id']);
        $this->assertSame(3, $guest['seat_count']);
        $this->assertSame([null, 8, 9], array_column($metadata['registrations'], 'buyer_id'));
        $this->assertContains(RegistrationMapper::FLAG_NO_BUYER, $metadata['flags']);
        $this->assertContains(RegistrationMapper::FLAG_BUYER_MISSING, $metadata['flags']);
    }

    public function testGiveawayLineIsFullyDiscounted(): void
    {
        $groups = RegistrationMapper::groups(
            self::CALENDARABLE,
            [$this->registration(['id' => 1])],
            [],
            [$this->item(['id' => 1, 'giveaway' => '{"type":"birthday"}'])],
        );

        $this->assertSame(35.0, $groups['lesson:512:u7']['participation']['discount']);
    }

    public function testRegistrationsOfAKanvasOrderAreSplitOffWithWhetherAnyIsLive(): void
    {
        [$mirrored, $orders] = RegistrationMapper::splitKanvasOrders([
            $this->registration(['id' => 1, 'kanvas_order_uuid' => 'AAAA-1']),
            $this->registration(['id' => 2, 'kanvas_order_uuid' => 'aaaa-1', 'deleted_at' => '2026-02-01 00:00:00']),
            $this->registration(['id' => 3, 'kanvas_order_uuid' => 'bbbb-2', 'deleted_at' => '2026-02-01 00:00:00']),
            $this->registration(['id' => 4]),
            $this->registration(['id' => 5, 'kanvas_order_uuid' => ' ']),
            // An orphaned sale_id reads no sales row, so its uuid is not the join's.
            $this->registration(['id' => 6, 'sale_found' => null, 'kanvas_order_uuid' => 'cccc-3']),
        ]);

        $this->assertSame([4, 5, 6], array_map(fn (object $row) => $row->id, $mirrored));
        $this->assertSame(['aaaa-1' => true, 'bbbb-2' => false], $orders);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function registration(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'calendarable_type' => 'lesson',
            'calendarable_id' => 512,
            'sale_id' => 10,
            'sale_found' => 10,
            'buyer_id' => 7,
            'sale_created_at' => '2026-01-01 18:30:00',
            'notes' => null,
            'created_at' => '2025-12-01 10:00:00',
            'deleted_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function attendee(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 100,
            'registration_id' => 1,
            'name' => 'Jane Doe',
            'checked_in_at' => null,
            'no_show' => 0,
            'deleted_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'sale_id' => 10,
            'option_type' => 'lesson',
            'option_id' => 512,
            'product_name' => 'Sunset Canvas',
            'category_name' => 'Classes',
            'quantity' => 1,
            'price' => '{"amount":3500,"currency":"USD"}',
            'discount' => null,
            'giveaway' => null,
            'refunded_at' => null,
        ]);
    }
}
