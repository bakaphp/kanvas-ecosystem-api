<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportInvoice;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Tests\TestCase;

class AcumaticaImportInvoiceTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'DocType' => 'INV',
            'RefNbr' => '000123',
            'AcctCD' => 'ACME-RETAIL',
            'DocDate' => '2026-03-17 00:00:00',
            'DueDate' => '2026-04-16 00:00:00',
            'CuryID' => 'USD',
            'CuryOrigDocAmt' => 1180.00,
            'CuryDocBal' => 400.00,
            'DocDesc' => 'March services',
        ], $overrides);
    }

    public function testMapsCoreFields(): void
    {
        $invoice = AcumaticaImportInvoice::fromRow($this->row());

        $this->assertSame('INV-000123', $invoice->externalId);
        $this->assertSame('000123', $invoice->refNbr);
        $this->assertSame('ACME-RETAIL', $invoice->customerCode);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(1180.00, $invoice->total);
        $this->assertSame(DocumentTypeEnum::INVOICE, $invoice->documentType);
        $this->assertSame('2026-03-17', $invoice->issuedDate?->toDateString());
        $this->assertSame('2026-04-16', $invoice->dueDate?->toDateString());
        $this->assertSame('March services', $invoice->memo);
    }

    public function testPaidIsOrigMinusBalance(): void
    {
        $this->assertSame(780.00, AcumaticaImportInvoice::fromRow($this->row())->paid);
        $this->assertSame(0.0, AcumaticaImportInvoice::fromRow($this->row(['CuryDocBal' => 1180.00]))->paid);
        $this->assertSame(1180.00, AcumaticaImportInvoice::fromRow($this->row(['CuryDocBal' => 0.0]))->paid);
    }

    public function testCreditMemoMapsToCreditNote(): void
    {
        $invoice = AcumaticaImportInvoice::fromRow($this->row(['DocType' => 'CRM']));

        $this->assertSame('CRM-000123', $invoice->externalId);
        $this->assertSame(DocumentTypeEnum::CREDIT_NOTE, $invoice->documentType);
    }

    public function testCurrencyFallsBackToUsd(): void
    {
        $this->assertSame('USD', AcumaticaImportInvoice::fromRow($this->row(['CuryID' => '']))->currency);
    }
}
