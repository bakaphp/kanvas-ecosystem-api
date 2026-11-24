<?php

declare(strict_types=1);

namespace Tests\Scribe\Unit;

use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine;
use Kanvas\Scribe\Ledger\Services\JournalEntryRoundingService;
use Kanvas\Scribe\Support\DocumentTotals;
use Tests\TestCase;

final class DocumentRoundingTest extends TestCase
{
    public function testTheBaseTotalIsTheSumOfItsRoundedParts(): void
    {
        [$native, $base] = DocumentTotals::compute([['subtotal' => 100.37, 'tax' => 18.0666, 'discount' => 0.0]], 58.9123);

        $this->assertSame(118.4366, $native['total']);
        $this->assertSame(5913.0276, $base['subtotal']);
        $this->assertSame(1064.345, $base['tax']);
        $this->assertSame(6977.3726, $base['total'], 'Not round(118.4366 × 58.9123) = 6977.3725.');
    }

    public function testFloatNoiseInLinesDoesNotReachTheTotals(): void
    {
        [$native] = DocumentTotals::compute([
            ['subtotal' => 0.1, 'tax' => 0.0],
            ['subtotal' => 0.2, 'tax' => 0.0],
        ], 1.0);

        $this->assertSame(0.3, $native['subtotal']);
        $this->assertSame(0.0, $native['discount']);
    }

    public function testARoundingResidualLandsOnTheNamedLineAndLeavesTheControlLine(): void
    {
        $lines = JournalEntryRoundingService::absorbInto([
            $this->line(debit: 6977.3725),
            $this->line(credit: 5913.0276),
            $this->line(credit: 1064.345),
        ], 1);

        $this->assertSame(6977.3725, $lines[0]->debit_base, 'AR keeps the document total.');
        $this->assertSame(5913.0275, $lines[1]->credit_base);
    }

    public function testAnImbalanceBeyondRoundingIsLeftForTheValidatorToRefuse(): void
    {
        $lines = JournalEntryRoundingService::absorbInto([
            $this->line(debit: 100.0),
            $this->line(credit: 99.0),
        ], 1);

        $this->assertSame(99.0, $lines[1]->credit_base);
    }

    private function line(float $debit = 0.0, float $credit = 0.0): JournalEntryLine
    {
        return new JournalEntryLine(
            account_id: 1,
            debit_native: $debit,
            credit_native: $credit,
            debit_base: $debit,
            credit_base: $credit,
            currency: 'USD',
            fx_rate_to_base: 1.0,
        );
    }
}
