<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Mappers\SaleMapper;
use Kanvas\Connectors\BrushCrazy\Support\RefundLedger;
use Kanvas\Connectors\BrushCrazy\Support\SaleRows;
use Tests\TestCase;

/**
 * Which BrushCrazy refunds of a Kanvas-charged sale Kanvas still owes the card, read the same way
 * on every sync.
 */
final class RefundLedgerTest extends TestCase
{
    private const string NOW = '2026-11-11T10:00:00+00:00';

    public function testAmountRefundOnTheKanvasPaymentIsOnePendingDelta(): void
    {
        $moves = SaleMapper::kanvasRefundMoves($this->sale(), [$this->mirror(200, [$this->kanvasPayment(['id' => 9, 'amount' => $this->money(1500)])])]);

        $this->assertSame(['sale:200'], array_keys($moves));
        $this->assertSame(1500, $moves['sale:200']['cents']);
        $this->assertSame('amount', $moves['sale:200']['kind']);

        $ledger = RefundLedger::reconcile([], $moves, self::NOW);

        $this->assertSame(['sale:200'], RefundLedger::pendingKeys($ledger));
        $this->assertSame(RefundLedger::ORIGIN_BRUSHCRAZY, $ledger['sale:200']['origin']);
    }

    public function testRepeatedSyncAddsNothingNew(): void
    {
        $moves = SaleMapper::kanvasRefundMoves($this->sale(), [$this->mirror(200, [$this->kanvasPayment(['amount' => $this->money(1500)])])]);
        $ledger = RefundLedger::reconcile([], $moves, self::NOW);
        $ledger['sale:200']['status'] = RefundLedger::REFUNDED;

        $again = RefundLedger::reconcile($ledger, $moves, self::NOW);

        $this->assertSame($ledger, $again);
        $this->assertSame([], RefundLedger::pendingKeys($again));
    }

    public function testSecondRefundIsOnlyItsOwnDelta(): void
    {
        $first = $this->mirror(200, [$this->kanvasPayment(['id' => 9, 'amount' => $this->money(1500)])]);
        $ledger = RefundLedger::reconcile([], SaleMapper::kanvasRefundMoves($this->sale(), [$first]), self::NOW);
        $ledger['sale:200']['status'] = RefundLedger::REFUNDED;

        $second = $this->mirror(201, [$this->kanvasPayment(['id' => 10, 'amount' => $this->money(700)])]);
        $ledger = RefundLedger::reconcile($ledger, SaleMapper::kanvasRefundMoves($this->sale(), [$first, $second]), self::NOW);

        $this->assertSame(['sale:201'], RefundLedger::pendingKeys($ledger));
        $this->assertSame(700, $ledger['sale:201']['cents']);
    }

    public function testStudioCreditRefundMovesNoMoney(): void
    {
        $itemToCredit = $this->mirror(200, []);
        $fullToCredit = $this->sale(['refunded_at' => '2026-11-10 12:00:00']);

        $this->assertSame([], SaleMapper::kanvasRefundMoves($fullToCredit, [$itemToCredit]));
    }

    public function testFullRefundToThePaymentIsTheKanvasPaymentAtItsCurrentAmount(): void
    {
        $sale = $this->sale(
            ['refunded_at' => '2026-11-10 12:00:00'],
            [$this->kanvasPayment(['id' => 5, 'amount' => $this->money(5920), 'refunded_at' => '2026-11-10 12:00:00'])]
        );
        $earlier = $this->mirror(200, [$this->kanvasPayment(['id' => 9, 'amount' => $this->money(1500)])], '2026-11-09 09:00:00');

        $moves = SaleMapper::kanvasRefundMoves($sale, [$earlier]);

        $this->assertSame(['sale:200', 'full:5'], array_keys($moves));
        $this->assertSame(5920, $moves['full:5']['cents']);
    }

    public function testRefundKanvasPushedIsRecordedWithoutMovingMoney(): void
    {
        $pushed = $this->mirror(200, [$this->kanvasPayment([
            'amount' => $this->money(1000),
            'card_number' => SaleMapper::KANVAS_REFUND_CARD_PREFIX . 'abc-123',
            'approval_code' => SaleMapper::KANVAS_REFUND_APPROVAL_CODE,
        ])]);

        $ledger = RefundLedger::reconcile([], SaleMapper::kanvasRefundMoves($this->sale(), [$pushed]), self::NOW);

        $this->assertSame([], RefundLedger::pendingKeys($ledger));
        $this->assertSame(RefundLedger::FROM_KANVAS, $ledger['sale:200']['status']);
        $this->assertSame('abc-123', $ledger['sale:200']['kanvas_refund_id']);
    }

    public function testFullRefundKanvasPushedIsRecognisedEvenWithoutTheMarker(): void
    {
        $sale = $this->sale(
            ['refunded_at' => '2026-11-10 12:00:00'],
            [$this->kanvasPayment(['id' => 5, 'amount' => $this->money(7420), 'refunded_at' => '2026-11-10 12:00:00'])]
        );
        $ledger = ['kanvas:abc-123' => ['origin' => RefundLedger::ORIGIN_KANVAS, 'status' => RefundLedger::PUSHED, 'full' => true]];

        $ledger = RefundLedger::reconcile($ledger, SaleMapper::kanvasRefundMoves($sale, []), self::NOW);

        $this->assertSame(RefundLedger::FROM_KANVAS, $ledger['full:5']['status']);
        $this->assertSame([], RefundLedger::pendingKeys($ledger));
    }

    public function testRevertedRefundThatWasPaidOutIsFlaggedNotCharged(): void
    {
        $ledger = ['full:5' => ['origin' => RefundLedger::ORIGIN_BRUSHCRAZY, 'status' => RefundLedger::REFUNDED, 'cents' => 7420, 'amount_cents' => 7420]];

        $ledger = RefundLedger::reconcile($ledger, [], self::NOW);

        $this->assertSame(RefundLedger::MANUAL, $ledger['reverted:full:5']['status']);
        $this->assertSame(RefundLedger::REFUNDED, $ledger['full:5']['status']);
        $this->assertSame([], RefundLedger::pendingKeys($ledger));
    }

    public function testRefundGoneBeforeItsJobRanIsSkipped(): void
    {
        $ledger = ['sale:200' => ['origin' => RefundLedger::ORIGIN_BRUSHCRAZY, 'status' => RefundLedger::PENDING, 'cents' => 1500]];

        $ledger = RefundLedger::reconcile($ledger, [], self::NOW);

        $this->assertSame(RefundLedger::SKIPPED, $ledger['sale:200']['status']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, object>  $payments
     */
    private function sale(array $overrides = [], array $payments = []): SaleRows
    {
        return new SaleRows(
            sale: (object) ($overrides + ['id' => 100, 'original_sale' => null, 'refunded_at' => null, 'kanvas_order_uuid' => 'site-order']),
            payments: $payments,
        );
    }

    /**
     * @param  array<int, object>  $payments
     */
    private function mirror(int $id, array $payments, string $refundedAt = '2026-11-10 09:00:00'): SaleRows
    {
        return new SaleRows(
            sale: (object) ['id' => $id, 'original_sale' => 100, 'refunded_at' => $refundedAt],
            items: [(object) ['id' => $id, 'category_name' => 'Refund', 'product_name' => 'Refund', 'quantity' => 1, 'price' => $this->money(1500)]],
            payments: $payments,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function kanvasPayment(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'name' => 'Kanvas stripe',
            'type' => SaleMapper::KANVAS_PAYMENT_TYPE,
            'amount' => $this->money(0),
            'card_number' => 'pi_test_123',
            'approval_code' => 'KANVAS',
            'transaction_id' => null,
            'completed_at' => '2026-11-01 18:00:00',
            'refunded_at' => null,
        ]);
    }

    private function money(int $cents): string
    {
        return '{"amount":' . $cents . ',"currency":"USD"}';
    }
}
