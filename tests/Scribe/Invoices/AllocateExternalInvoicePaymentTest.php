<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Tests\Scribe\ScribeTestCase;

/**
 * An imported invoice never booked its AR in Kanvas (the ERP is the books), so paying it must not post
 * the DR Cash / CR AR entry — that would credit an AR balance Kanvas never debited. A Kanvas-issued
 * invoice still posts it.
 */
class AllocateExternalInvoicePaymentTest extends ScribeTestCase
{
    private function paymentEntries(): int
    {
        return JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->count();
    }

    public function test_paying_an_imported_invoice_marks_it_paid_without_a_journal_entry(): void
    {
        $invoice = $this->importTestInvoice(687.76);
        $before = $this->paymentEntries();

        new AllocateInvoicePaymentAction(invoice: $invoice, amountNative: 687.76, user: static::$cachedUser)
            ->execute();

        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->document_status);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->balance_due_native, 0.001);
        $this->assertSame($before, $this->paymentEntries());
    }

    public function test_paying_a_kanvas_issued_invoice_still_posts_its_journal_entry(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Globex'), 500.0);
        $before = $this->paymentEntries();

        new AllocateInvoicePaymentAction(invoice: $invoice, amountNative: 500.0, user: static::$cachedUser)
            ->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->fresh()->document_status);
        $this->assertSame($before + 1, $this->paymentEntries());
    }
}
