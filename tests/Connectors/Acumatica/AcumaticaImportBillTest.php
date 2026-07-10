<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportBill;
use Tests\TestCase;

class AcumaticaImportBillTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'DocType' => 'BIL',
            'RefNbr' => 'AP-5001',
            'AcctCD' => 'GLOBEX-SUP',
            'DocDate' => '2026-03-10 00:00:00',
            'DueDate' => '2026-04-09 00:00:00',
            'CuryID' => 'USD',
            'CuryOrigDocAmt' => 800.00,
            'CuryDocBal' => 300.00,
            'DocDesc' => 'Raw materials',
        ], $overrides);
    }

    public function testMapsCoreFields(): void
    {
        $bill = AcumaticaImportBill::fromRow($this->row());

        $this->assertSame('BIL-AP-5001', $bill->externalId);
        $this->assertSame('AP-5001', $bill->refNbr);
        $this->assertSame('GLOBEX-SUP', $bill->vendorCode);
        $this->assertSame('USD', $bill->currency);
        $this->assertSame(800.00, $bill->total);
        $this->assertSame('2026-03-10', $bill->billDate?->toDateString());
        $this->assertSame('2026-04-09', $bill->dueDate?->toDateString());
        $this->assertSame('Raw materials', $bill->memo);
    }

    public function testPaidIsOrigMinusBalance(): void
    {
        $this->assertSame(500.00, AcumaticaImportBill::fromRow($this->row())->paid);
        $this->assertSame(0.0, AcumaticaImportBill::fromRow($this->row(['CuryDocBal' => 800.00]))->paid);
        $this->assertSame(800.00, AcumaticaImportBill::fromRow($this->row(['CuryDocBal' => 0.0]))->paid);
    }

    public function testCurrencyFallsBackToUsd(): void
    {
        $this->assertSame('USD', AcumaticaImportBill::fromRow($this->row(['CuryID' => '']))->currency);
    }
}
