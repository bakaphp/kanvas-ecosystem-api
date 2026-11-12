<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\SaleMapper;
use Kanvas\Connectors\BrushCrazy\Support\AddonPricer;
use Kanvas\Connectors\BrushCrazy\Support\SaleRows;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Tests\TestCase;

final class SaleMapperTest extends TestCase
{
    public function testTotalIsSubtotalMinusDiscountsPlusTaxes(): void
    {
        $sale = $this->sale(
            items: [
                $this->item(['id' => 1, 'price' => $this->money(3500), 'quantity' => 2]),
                $this->item(['id' => 2, 'price' => $this->money(1000)]),
            ],
            taxes: [$this->tax(560)],
            discounts: [$this->discount(['amount' => $this->money(500)])],
            payments: [$this->payment(['amount' => $this->money(8000)])],
        );

        $totals = SaleMapper::map($sale, [], AddonPricer::empty())['order']['metadata']['brushcrazy']['totals'];

        $this->assertSame(8000, $totals['subtotal_cents']);
        $this->assertSame(500, $totals['discount_cents']);
        $this->assertSame(560, $totals['tax_cents']);
        $this->assertSame(8060, $totals['total_cents']);
        $this->assertSame(60, $totals['balance_cents']);
        $this->assertSame(8060, $totals['net_cents']);
    }

    public function testOrderColumnsAreDollars(): void
    {
        $order = SaleMapper::map(
            $this->sale(
                items: [$this->item(['price' => $this->money(2500)])],
                taxes: [$this->tax(200)],
                payments: [$this->payment(['amount' => $this->money(2700)])],
            ),
            [],
            AddonPricer::empty()
        )['order'];

        $this->assertSame(27.0, $order['total_gross_amount']);
        $this->assertSame(25.0, $order['total_net_amount']);
        $this->assertSame(2.0, $order['tax_amount']);
        $this->assertSame(100, $order['order_number']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order['payment_status']);
        $this->assertSame('2024-03-01 18:00:00', $order['paid_at']);
    }

    /** `tax_exempt` holds the exemption reason, so any non-null value means no tax. */
    public function testTaxExemptSaleChargesNoTaxEvenWithTaxRows(): void
    {
        $sale = $this->sale(
            overrides: ['tax_exempt' => 'Non-profit'],
            items: [$this->item(['price' => $this->money(1000)])],
            taxes: [$this->tax(80)],
        );

        $totals = SaleMapper::totals($sale, AddonPricer::empty());

        $this->assertSame(0, $totals['tax_cents']);
        $this->assertSame(1000, $totals['total_cents']);
    }

    /** An item refunded on its own leaves the total but stays on the order, hidden. */
    public function testRefundedLineLeavesTheTotalAndIsHidden(): void
    {
        $mapped = SaleMapper::map(
            $this->sale(items: [
                $this->item(['id' => 1, 'price' => $this->money(3000)]),
                $this->item(['id' => 2, 'price' => $this->money(2000), 'refunded_at' => '2024-03-02 10:00:00']),
            ]),
            [],
            AddonPricer::empty()
        );

        $this->assertSame(3000, $mapped['order']['metadata']['brushcrazy']['totals']['total_cents']);
        $this->assertTrue($mapped['items'][0]['attributes']['is_public']);
        $this->assertFalse($mapped['items'][1]['attributes']['is_public']);
        $this->assertSame('2024-03-02 10:00:00', $mapped['items'][1]['attributes']['metadata']['brushcrazy']['refunded_at']);
    }

    /**
     * Refunding one unit of a quantity-3 line decrements it to 2 at the source and books a mirror
     * with one copied unit. The parent total already reflects the 2; the mirror is netted into
     * metadata, not counted again.
     */
    public function testQuantityDecrementRefundIsNettedNotDoubleCounted(): void
    {
        $parent = $this->sale(
            items: [$this->item(['price' => $this->money(2000), 'quantity' => 2])],
            payments: [$this->payment(['amount' => $this->money(4000)])],
        );
        $mirror = $this->mirror(
            items: [$this->item(['id' => 9, 'price' => $this->money(2000), 'quantity' => 1])],
            payments: [$this->payment(['id' => 99, 'amount' => $this->money(2000)])],
        );

        $brushcrazy = SaleMapper::map($parent, [$mirror], AddonPricer::empty())['order']['metadata']['brushcrazy'];

        $this->assertSame('partial', $brushcrazy['refund_status']);
        $this->assertSame(4000, $brushcrazy['totals']['total_cents']);
        $this->assertSame(2000, $brushcrazy['totals']['item_refunded_cents']);
        $this->assertSame(0, $brushcrazy['totals']['amount_refunded_cents']);
        $this->assertSame(4000, $brushcrazy['totals']['net_cents']);
        $this->assertSame(2000, $brushcrazy['totals']['refunded_cents']);
        $this->assertSame('item', $brushcrazy['refunds'][0]['kind']);
        $this->assertSame('payment', $brushcrazy['refunds'][0]['via']);
        $this->assertSame(200, $brushcrazy['refunds'][0]['sale_id']);
    }

    /** An amount refund leaves the parent's items and total alone, so it comes off the net. */
    public function testAmountRefundComesOffTheNet(): void
    {
        $parent = $this->sale(
            items: [$this->item(['price' => $this->money(5000)])],
            payments: [$this->payment(['amount' => $this->money(3500)])],
        );
        $mirror = $this->mirror(
            items: [$this->item(['product_name' => 'Refund', 'category_name' => 'Refund', 'price' => $this->money(1500), 'taxable' => 0])],
            payments: [$this->payment(['amount' => $this->money(1500)])],
        );

        $brushcrazy = SaleMapper::map($parent, [$mirror], AddonPricer::empty())['order']['metadata']['brushcrazy'];

        $this->assertSame('amount', $brushcrazy['refunds'][0]['kind']);
        $this->assertSame(1500, $brushcrazy['totals']['amount_refunded_cents']);
        $this->assertSame(3500, $brushcrazy['totals']['net_cents']);
        // Sale::balanceAfterRefunds(): 5000 total − 3500 paid − 1500 amount refunded.
        $this->assertSame(0, $brushcrazy['totals']['balance_after_refunds_cents']);
    }

    /** The credit branch never books a payment on the mirror, which is how the receipt tells it apart. */
    public function testStudioCreditRefundIsTrackedApart(): void
    {
        $parent = $this->sale(
            items: [$this->item(['price' => $this->money(3000)])],
            payments: [$this->payment(['amount' => $this->money(5000)])],
        );
        $mirror = $this->mirror(items: [$this->item(['price' => $this->money(2000)])]);

        $totals = SaleMapper::map($parent, [$mirror], AddonPricer::empty())['order']['metadata']['brushcrazy']['totals'];

        $this->assertSame(2000, $totals['credit_refunded_cents']);
        // 3000 − 5000 paid + 2000 credited: nothing owed either way.
        $this->assertSame(0, $totals['balance_after_refunds_cents']);
    }

    public function testFullRefundKeepsTheTotalAndNetsToZero(): void
    {
        $order = SaleMapper::map(
            $this->sale(
                overrides: ['refunded_at' => '2024-03-05 12:00:00'],
                items: [$this->item(['price' => $this->money(4500)])],
            ),
            [],
            AddonPricer::empty()
        )['order'];

        $this->assertSame(PaymentStatusEnum::REVERSED->value, $order['payment_status']);
        $this->assertNull($order['paid_at']);
        $this->assertSame(45.0, $order['total_gross_amount']);
        $this->assertSame('full', $order['metadata']['brushcrazy']['refund_status']);
        $this->assertSame(0, $order['metadata']['brushcrazy']['totals']['net_cents']);
        $this->assertSame(4500, $order['metadata']['brushcrazy']['totals']['refunded_cents']);
        $this->assertSame('credit', $order['metadata']['brushcrazy']['totals']['full_refund_via']);
    }

    /**
     * A card amount refund decrements the parent's card payment; the later full refund hands back
     * only what that payment still holds, so the two must not add up past what was paid.
     */
    public function testFullRefundAfterAnAmountRefundOnlyRefundsTheRemainder(): void
    {
        $parent = $this->sale(
            overrides: ['refunded_at' => '2024-03-05 12:00:00'],
            items: [$this->item(['price' => $this->money(5000)])],
            payments: [$this->payment([
                'type' => 'CreditCard',
                'amount' => $this->money(3500),
                'refunded_at' => '2024-03-05 12:00:00',
            ])],
        );
        $mirror = $this->mirror(
            items: [$this->item(['product_name' => 'Refund', 'category_name' => 'Refund', 'price' => $this->money(1500), 'taxable' => 0])],
            payments: [$this->payment(['id' => 99, 'type' => 'CreditCard', 'amount' => $this->money(1500)])],
        );

        $totals = SaleMapper::map($parent, [$mirror], AddonPricer::empty())['order']['metadata']['brushcrazy']['totals'];

        $this->assertSame(3500, $totals['full_refund_cents']);
        $this->assertSame('payment', $totals['full_refund_via']);
        $this->assertSame(5000, $totals['refunded_cents']);
        $this->assertSame(0, $totals['net_cents']);
    }

    /** A cash partial refund stamps the cash payment, so the later full refund does not re-count it. */
    public function testPaymentStampedByAnEarlierPartialRefundIsNotPartOfTheFullRefund(): void
    {
        $parent = $this->sale(
            overrides: ['refunded_at' => '2024-03-05 12:00:00'],
            items: [
                $this->item(['id' => 1, 'price' => $this->money(3000)]),
                $this->item(['id' => 2, 'price' => $this->money(2000), 'refunded_at' => '2024-03-02 10:00:00']),
            ],
            payments: [
                $this->payment(['id' => 1, 'amount' => $this->money(2000), 'refunded_at' => '2024-03-02 09:59:59']),
                $this->payment(['id' => 2, 'type' => 'CreditCard', 'amount' => $this->money(3000), 'refunded_at' => '2024-03-05 11:59:59']),
            ],
        );
        $mirror = $this->mirror(
            items: [$this->item(['id' => 9, 'price' => $this->money(2000)])],
            payments: [$this->payment(['id' => 99, 'amount' => $this->money(2000)])],
        );

        $totals = SaleMapper::map($parent, [$mirror], AddonPricer::empty())['order']['metadata']['brushcrazy']['totals'];

        $this->assertSame(2000, $totals['item_refunded_cents']);
        $this->assertSame(3000, $totals['full_refund_cents']);
        $this->assertSame(5000, $totals['refunded_cents']);
    }

    /**
     * The mirror line is replicated from the original before the original is stamped, so a stamped
     * mirror line means an already-refunded line was refunded again — the source's `total()` of
     * that mirror is 0 and it moved no money, which is what is netted here too.
     */
    public function testStampedMirrorLineRefundsNothingLikeTheSource(): void
    {
        $parent = $this->sale(
            items: [$this->item(['price' => $this->money(3000), 'refunded_at' => '2024-03-02 10:00:00'])],
            payments: [$this->payment(['amount' => $this->money(3000)])],
        );
        $mirror = $this->mirror(items: [$this->item(['id' => 9, 'price' => $this->money(3000), 'refunded_at' => '2024-03-02 10:00:00'])]);

        $brushcrazy = SaleMapper::map($parent, [$mirror], AddonPricer::empty())['order']['metadata']['brushcrazy'];

        $this->assertSame(0, $brushcrazy['refunds'][0]['total_cents']);
        $this->assertSame(0, $brushcrazy['totals']['item_refunded_cents']);
        $this->assertSame(0, $brushcrazy['totals']['credit_refunded_cents']);
    }

    /** OrderItem stores only a 2-decimal unit price, so unit × quantity must land on the line total. */
    public function testUnitPriceTimesQuantityIsExactlyTheLineTotal(): void
    {
        $item = $this->item([
            'price' => $this->money(1000),
            'quantity' => 3,
            'discount' => '{"name":"Staff","type":"fixed","amount":{"amount":"1","currency":"USD"}}',
        ]);

        $line = SaleMapper::itemLine($item, AddonPricer::empty());
        $attributes = SaleMapper::map($this->sale(items: [$item]), [], AddonPricer::empty())['items'][0]['attributes'];

        $this->assertSame(2997, $line['total_cents']);
        $this->assertSame(999, $line['unit_cents']);
        $this->assertSame(9.99, $attributes['unit_price_gross_amount']);
        $this->assertSame($line['total_cents'], (int) round($attributes['unit_price_gross_amount'] * 100) * $attributes['quantity']);
    }

    public function testGiveawayLineHasNoUnitPrice(): void
    {
        $attributes = SaleMapper::map(
            $this->sale(items: [$this->item(['quantity' => 2, 'giveaway' => '{"type":"Cause"}'])]),
            [],
            AddonPricer::empty()
        )['items'][0]['attributes'];

        $this->assertSame(0.0, $attributes['unit_price_gross_amount']);
    }

    /** Souk has no partially-paid status; an order with money still owed is not PAID. */
    public function testOpenBalanceIsPendingNotPaid(): void
    {
        $order = SaleMapper::map(
            $this->sale(
                items: [$this->item(['price' => $this->money(5000)])],
                payments: [$this->payment(['amount' => $this->money(4000)])],
            ),
            [],
            AddonPricer::empty()
        )['order'];

        $this->assertSame(PaymentStatusEnum::PENDING->value, $order['payment_status']);
        $this->assertNull($order['paid_at']);
    }

    /**
     * A 1001-cent line refunded off two 5000 payments: each share is 500.5, rounded to 501, so the
     * payments drop by 1002 and the source prints a 1-cent balance on a fully paid sale.
     */
    public function testPerPaymentRefundRoundingDoesNotLeaveAPaidSalePending(): void
    {
        $parent = $this->sale(
            items: [
                $this->item(['id' => 1, 'price' => $this->money(8999)]),
                $this->item(['id' => 2, 'price' => $this->money(1001), 'refunded_at' => '2024-03-02 10:00:00']),
            ],
            payments: [
                $this->payment(['id' => 1, 'type' => 'CreditCard', 'amount' => $this->money(4499)]),
                $this->payment(['id' => 2, 'amount' => $this->money(4499)]),
            ],
        );
        $mirror = $this->mirror(
            items: [$this->item(['id' => 9, 'price' => $this->money(1001)])],
            payments: [
                $this->payment(['id' => 98, 'type' => 'CreditCard', 'amount' => $this->money(501)]),
                $this->payment(['id' => 99, 'amount' => $this->money(501)]),
            ],
        );

        $order = SaleMapper::map($parent, [$mirror], AddonPricer::empty())['order'];

        $this->assertSame(1, $order['metadata']['brushcrazy']['totals']['balance_after_refunds_cents']);
        $this->assertSame(PaymentStatusEnum::PAID->value, $order['payment_status']);
    }

    public function testDiscountAmountIncludesLineDiscounts(): void
    {
        $order = SaleMapper::map(
            $this->sale(
                items: [
                    $this->item(['id' => 1, 'quantity' => 2, 'discount' => '{"name":"Staff","type":"fixed","amount":{"amount":"300","currency":"USD"}}']),
                    // Refunded and giveaway lines are not in the subtotal, so their discount is not either.
                    $this->item(['id' => 2, 'refunded_at' => '2024-03-02 10:00:00', 'discount' => '{"name":"Staff","type":"fixed","amount":{"amount":"300","currency":"USD"}}']),
                    $this->item(['id' => 3, 'giveaway' => '{"type":"Cause"}', 'discount' => '{"name":"Staff","type":"fixed","amount":{"amount":"300","currency":"USD"}}']),
                ],
                discounts: [$this->discount(['amount' => $this->money(500)])],
            ),
            [],
            AddonPricer::empty()
        )['order'];

        $this->assertSame(600, $order['metadata']['brushcrazy']['totals']['item_discount_cents']);
        $this->assertSame(11.0, $order['discount_amount']);
    }

    /** The source column is malformed (`{"type":..,"0":"reason"}`) but decodes; the line is free. */
    public function testGiveawayLineIsFreeButKeepsItsValue(): void
    {
        $item = $this->item(['price' => $this->money(3000), 'giveaway' => '{"type":"Cause","0":"Fundraiser","1":"Approved"}']);
        $line = SaleMapper::itemLine($item, AddonPricer::empty());

        $this->assertSame(0, $line['total_cents']);
        $this->assertSame(3000, $line['subtotal_cents']);
        $this->assertSame('Fundraiser', $line['giveaway'][0]);
        $this->assertNull(SaleMapper::giveaway($this->item(['giveaway' => '{"type":null}'])));
        $this->assertSame(2500, SaleMapper::itemLine($this->item(['giveaway' => '{"type":null}']), AddonPricer::empty())['total_cents']);
    }

    public function testItemDiscountNeedsANameAndAMoneyAmount(): void
    {
        $unnamed = $this->item(['discount' => '{"name":null,"type":"fixed","amount":{"amount":"500","currency":"USD"},"reason":null}']);
        $named = $this->item(['discount' => '{"name":"Staff","type":"fixed","amount":{"amount":"500","currency":"USD"},"reason":null}']);
        $flat = $this->item(['discount' => '{"name":"Staff","amount":500}']);

        $this->assertSame(0, SaleMapper::itemDiscountCents($unnamed));
        $this->assertSame(500, SaleMapper::itemDiscountCents($named));
        $this->assertSame(0, SaleMapper::itemDiscountCents($flat));

        $discounted = $this->item([
            'price' => $this->money(2500),
            'quantity' => 2,
            'discount' => '{"name":"Staff","type":"fixed","amount":{"amount":"500","currency":"USD"}}',
        ]);

        // (price − discount) × quantity
        $this->assertSame(4000, SaleMapper::itemLine($discounted, AddonPricer::empty())['total_cents']);
    }

    /**
     * A percent discount is converted to money when applied, so its amount is cents. One never
     * converted still holds `{"percent": N}` in hundredths of a percent, which the source totals as 0.
     */
    public function testPercentDiscountShapes(): void
    {
        $sale = $this->sale(
            items: [$this->item(['price' => $this->money(10000)])],
            discounts: [
                $this->discount(['name' => 'Ten off', 'basis' => 'percent', 'amount' => $this->money(1000)]),
                $this->discount(['name' => 'Legacy', 'type' => 'percent', 'basis' => 'percent', 'amount' => '{"percent":1500}']),
            ],
        );

        $brushcrazy = SaleMapper::map($sale, [], AddonPricer::empty())['order']['metadata']['brushcrazy'];

        $this->assertSame(1000, $brushcrazy['totals']['discount_cents']);
        $this->assertSame(9000, $brushcrazy['totals']['total_cents']);
        $this->assertSame(15.0, $brushcrazy['discounts'][1]['percent']);
        $this->assertSame(0, $brushcrazy['discounts'][1]['amount_cents']);
        $this->assertSame('Ten off, Legacy', SaleMapper::map($sale, [], AddonPricer::empty())['order']['discount_name']);
    }

    /** A refunded item's add-ons are stored as a list, and the source prices a list at nothing. */
    public function testAddonsArePricedFromAMapButNotFromAList(): void
    {
        $pricer = AddonPricer::fromRows([
            (object) ['name' => 'Grout Fee', 'type' => 'fixed', 'amount' => '{"amount":500,"currency":"USD"}', 'value' => null],
        ]);

        $map = SaleMapper::itemLine($this->item(['price' => $this->money(2000), 'quantity' => 2, 'addons' => '{"Grout Fee":true}']), $pricer);
        $list = SaleMapper::itemLine($this->item(['price' => $this->money(2000), 'addons' => '["Grout Fee"]']), $pricer);

        $this->assertSame(5000, $map['total_cents']);
        $this->assertSame([['name' => 'Grout Fee', 'cents' => 500]], $map['addons']);
        $this->assertSame(2000, $list['total_cents']);
    }

    public function testOnlyCompletedLiveParentSalesAreImportable(): void
    {
        $this->assertTrue(SaleMapper::isImportable($this->sale()));
        $this->assertFalse(SaleMapper::isImportable($this->sale(overrides: ['completed_at' => null])));
        $this->assertFalse(SaleMapper::isImportable($this->sale(overrides: ['deleted_at' => '2024-03-01 19:00:00'])));
        $this->assertFalse(SaleMapper::isImportable($this->mirror()));
    }

    public function testClassSeatCarriesItsCalendarableAndPaintingsTheirIds(): void
    {
        $mapped = SaleMapper::map(
            $this->sale(items: [
                $this->item(['id' => 1, 'option_type' => 'lesson', 'option_id' => 123, 'first_option_id' => 7]),
                $this->item(['id' => 2, 'option_type' => 'painting', 'option_id' => 55]),
            ]),
            [],
            AddonPricer::empty()
        );

        $this->assertSame('lesson:123', $mapped['items'][0]['attributes']['metadata']['calendarable']);
        $this->assertSame(7, $mapped['items'][0]['attributes']['metadata']['brushcrazy']['first_painting_id']);
        $this->assertSame(SaleLineKindEnum::CLASS_SEAT, $mapped['items'][0]['kind']);
        $this->assertSame(55, $mapped['items'][1]['attributes']['metadata']['brushcrazy']['painting_id']);
        $this->assertArrayNotHasKey('calendarable', $mapped['items'][1]['attributes']['metadata']);
    }

    public function testGiftCardNumberComesFromTheItemMetadata(): void
    {
        $mapped = SaleMapper::map(
            $this->sale(items: [$this->item(['product_name' => 'Gift Card', 'category_name' => 'Gift Cards', 'metadata' => '{"number":"GC-1234"}'])]),
            [],
            AddonPricer::empty()
        );

        $this->assertSame(SaleLineKindEnum::GIFT_CARD, $mapped['items'][0]['kind']);
        $this->assertSame('GC-1234', $mapped['items'][0]['attributes']['metadata']['brushcrazy']['gift_card_number']);
    }

    public function testCreditCardIsCutToLastFourButStoredValueCardsKeepTheirNumber(): void
    {
        $payments = SaleMapper::map(
            $this->sale(payments: [
                $this->payment(['id' => 1, 'type' => 'CreditCard', 'card_number' => 'XXXX-XXXX-XXXX-4242']),
                $this->payment(['id' => 2, 'type' => 'GiftCard', 'card_number' => 'GC-1234']),
            ]),
            [],
            AddonPricer::empty()
        )['order']['metadata']['brushcrazy']['payments'];

        $this->assertSame('4242', $payments[0]['card']);
        $this->assertSame('GC-1234', $payments[1]['card']);
    }

    public function testLineKindClassification(): void
    {
        $this->assertSame(SaleLineKindEnum::REFUND, SaleLineKindEnum::classify($this->item(['category_name' => 'Refund'])));
        $this->assertSame(SaleLineKindEnum::TIP, SaleLineKindEnum::classify($this->item(['category_name' => 'Tips'])));
        $this->assertSame(SaleLineKindEnum::GIFT_CARD, SaleLineKindEnum::classify($this->item(['category_name' => 'Gift Cards'])));
        $this->assertSame(SaleLineKindEnum::EVENT_DEPOSIT, SaleLineKindEnum::classify($this->item(['product_name' => 'Event Deposit'])));
        $this->assertSame(SaleLineKindEnum::EVENT_DEPOSIT, SaleLineKindEnum::classify($this->item(['category_name' => 'Party Deposits', 'option_type' => 'event', 'option_id' => 3])));
        $this->assertSame(SaleLineKindEnum::CLASS_SEAT, SaleLineKindEnum::classify($this->item(['option_type' => 'workshop', 'option_id' => 3])));
        $this->assertSame(SaleLineKindEnum::PAINTING, SaleLineKindEnum::classify($this->item(['option_type' => 'painting', 'option_id' => 3])));
        $this->assertSame(SaleLineKindEnum::MISC, SaleLineKindEnum::classify($this->item(['option_type' => 'lesson', 'option_id' => null])));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, object>  $items
     * @param  array<int, object>  $taxes
     * @param  array<int, object>  $discounts
     * @param  array<int, object>  $payments
     */
    private function sale(
        array $overrides = [],
        array $items = [],
        array $taxes = [],
        array $discounts = [],
        array $payments = []
    ): SaleRows {
        return new SaleRows(
            sale: (object) ($overrides + [
                'id' => 100,
                'ulid' => '01HQ0000000000000000000000',
                'customer_id' => 5,
                'studio_id' => 2,
                'tax_exempt' => null,
                'completed_at' => '2024-03-01 18:00:00',
                'refunded_at' => null,
                'original_sale' => null,
                'cart_id' => null,
                'created_at' => '2024-03-01 17:30:00',
                'deleted_at' => null,
            ]),
            items: $items,
            taxes: $taxes,
            discounts: $discounts,
            payments: $payments,
        );
    }

    /**
     * @param  array<int, object>  $items
     * @param  array<int, object>  $payments
     */
    private function mirror(array $items = [], array $payments = []): SaleRows
    {
        return $this->sale(
            overrides: ['id' => 200, 'original_sale' => 100, 'refunded_at' => '2024-03-02 10:00:00'],
            items: $items,
            payments: $payments,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'product_id' => null,
            'product_name' => 'Canvas 11x14',
            'category_name' => 'Canvas',
            'quantity' => 1,
            'price' => $this->money(2500),
            'option_type' => null,
            'option_id' => null,
            'double_sided' => 0,
            'first_option_id' => null,
            'second_option_id' => null,
            'addons' => null,
            'extra_colors' => 0,
            'extra_gold' => 0,
            'extra_silver' => 0,
            'discount' => null,
            'giveaway' => null,
            'metadata' => null,
            'taxable' => 1,
            'refunded_at' => null,
            'created_at' => '2024-03-01 17:31:00',
        ]);
    }

    private function tax(int $cents): object
    {
        return (object) ['id' => 1, 'name' => 'Sales Tax', 'type' => 'fixed', 'rate' => '7', 'amount' => $this->money($cents)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function discount(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'name' => 'Promo',
            'type' => 'fixed',
            'basis' => 'fixed',
            'amount' => $this->money(0),
            'option_type' => null,
            'option_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payment(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'name' => 'Cash',
            'type' => 'Cash',
            'amount' => $this->money(0),
            'card_number' => null,
            'approval_code' => null,
            'transaction_id' => null,
            'completed_at' => '2024-03-01 18:00:00',
            'refunded_at' => null,
        ]);
    }

    private function money(int $cents): string
    {
        return '{"amount":' . $cents . ',"currency":"USD"}';
    }
}
