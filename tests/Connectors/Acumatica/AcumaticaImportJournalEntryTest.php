<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportJournalEntry;
use Tests\TestCase;

class AcumaticaImportJournalEntryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function header(array $overrides = []): array
    {
        return array_merge([
            'Module' => 'GL',
            'BatchNbr' => '000123',
            'DateEntered' => '2026-03-17 00:00:00',
            'FinPeriodID' => '202603',
            'CuryID' => 'USD',
            'Description' => 'Monthly accrual',
            'Status' => 'P',
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(): array
    {
        return [
            ['AccountCD' => '1200', 'TranDesc' => 'AR', 'CuryID' => 'USD', 'DebitAmt' => 500.00, 'CreditAmt' => 0.0, 'CuryDebitAmt' => 500.00, 'CuryCreditAmt' => 0.0],
            ['AccountCD' => '4000', 'TranDesc' => 'Revenue', 'CuryID' => 'USD', 'DebitAmt' => 0.0, 'CreditAmt' => 500.00, 'CuryDebitAmt' => 0.0, 'CuryCreditAmt' => 500.00],
        ];
    }

    public function testMapsHeader(): void
    {
        $je = AcumaticaImportJournalEntry::from($this->header(), $this->lines());

        $this->assertSame('000123', $je->externalId);
        $this->assertSame('2026-03-17', $je->postedAt?->toDateString());
        $this->assertSame('Monthly accrual', $je->memo);
        $this->assertSame('USD', $je->currency);
        $this->assertSame('202603', $je->finPeriodId);
        $this->assertSame('GL', $je->module);
    }

    public function testMapsLines(): void
    {
        $je = AcumaticaImportJournalEntry::from($this->header(), $this->lines());

        $this->assertCount(2, $je->lines);
        $this->assertSame('1200', $je->lines[0]->accountCd);
        $this->assertSame(500.00, $je->lines[0]->debitNative);
        $this->assertSame(0.0, $je->lines[0]->creditNative);
        $this->assertSame('4000', $je->lines[1]->accountCd);
        $this->assertSame(500.00, $je->lines[1]->creditBase);
    }

    public function testSkipsLinesWithoutAccountCode(): void
    {
        $lines = $this->lines();
        $lines[] = ['AccountCD' => '', 'DebitAmt' => 10.0, 'CreditAmt' => 0.0];

        $je = AcumaticaImportJournalEntry::from($this->header(), $lines);

        $this->assertCount(2, $je->lines);
    }

    public function testDropsFullyZeroLines(): void
    {
        $lines = $this->lines();
        $lines[] = ['AccountCD' => '9999', 'DebitAmt' => 0.0, 'CreditAmt' => 0.0, 'CuryDebitAmt' => 0.0, 'CuryCreditAmt' => 0.0];

        $je = AcumaticaImportJournalEntry::from($this->header(), $lines);

        $this->assertCount(2, $je->lines);
    }

    public function testLineCurrencyFallsBackToBatchCurrency(): void
    {
        $lines = [
            ['AccountCD' => '1200', 'DebitAmt' => 500.00, 'CreditAmt' => 0.0, 'CuryDebitAmt' => 500.00, 'CuryCreditAmt' => 0.0, 'CuryID' => ''],
        ];

        $je = AcumaticaImportJournalEntry::from($this->header(['CuryID' => 'EUR']), $lines);

        $this->assertSame('EUR', $je->lines[0]->currency);
    }
}
