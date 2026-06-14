<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesReceipts;

use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Kanvas\Scribe\SalesReceipts\Exceptions\InvalidSalesReceiptTransitionException;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Scribe\SalesReceipts\Services\SalesReceiptStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesReceiptStateMachineTest extends TestCase
{
    private SalesReceiptStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new SalesReceiptStateMachine();
    }

    #[DataProvider('validTransitionProvider')]
    public function test_valid_transition_passes(SalesReceiptStatusEnum $from, SalesReceiptStatusEnum $to): void
    {
        $receipt = new SalesReceipt();
        $receipt->status = $from;
        $this->machine->assertTransition($receipt, $to);
        $this->assertTrue(true, "{$from->value} → {$to->value} should be allowed.");
    }

    public static function validTransitionProvider(): array
    {
        return [
            'recorded → voided' => [SalesReceiptStatusEnum::RECORDED, SalesReceiptStatusEnum::VOIDED],
            'recorded → recorded (idempotent)' => [SalesReceiptStatusEnum::RECORDED, SalesReceiptStatusEnum::RECORDED],
            'voided → voided (idempotent)' => [SalesReceiptStatusEnum::VOIDED, SalesReceiptStatusEnum::VOIDED],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_transition_throws(SalesReceiptStatusEnum $from, SalesReceiptStatusEnum $to): void
    {
        $receipt = new SalesReceipt();
        $receipt->status = $from;
        $this->expectException(InvalidSalesReceiptTransitionException::class);
        $this->machine->assertTransition($receipt, $to);
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'voided → recorded (terminal)' => [SalesReceiptStatusEnum::VOIDED, SalesReceiptStatusEnum::RECORDED],
        ];
    }
}
