<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DraftCreditNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DraftDebitNoteTool;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Tests\Scribe\ScribeTestCase;

/** A company with no approval policy approves through the legacy queue; a note there must still issue as a note. */
final class LegacyNoteApprovalTest extends ScribeTestCase
{
    public function test_an_approved_credit_note_is_numbered_as_a_credit_note_and_lowers_the_invoice_balance(): void
    {
        $invoice = $this->invoiceWithApprover();

        $draft = new DraftCreditNoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)(
            invoice_id: $invoice->getId(),
            lines: [['description' => 'Price adjustment', 'unit_price' => 200]],
            reason: 'Agreed discount',
        );

        $this->assertTrue($draft['drafted']);
        $this->assertTrue($draft['pending_approval']);
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT->value, $draft['status']);
        $this->assertStringContainsString('waiting for a person', $draft['next']);

        $this->approve((int) $draft['note_id']);

        $note = Invoice::query()->findOrFail($draft['note_id']);
        $this->assertSame(DocumentTypeEnum::CREDIT_NOTE, $note->document_type);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $note->document_status);
        $this->assertStringStartsWith('CRN', $note->invoice_number);
        $this->assertSame(800.0, (float) $invoice->fresh()->balance_due_native);
    }

    public function test_an_approved_debit_note_is_numbered_as_a_debit_note_and_opens_its_own_balance(): void
    {
        $invoice = $this->invoiceWithApprover();

        $draft = new DraftDebitNoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)(
            invoice_id: $invoice->getId(),
            lines: [['description' => 'Late fee', 'unit_price' => 50]],
            reason: 'Late payment fee',
        );

        $this->assertTrue($draft['pending_approval']);

        $this->approve((int) $draft['note_id']);

        $note = Invoice::query()->findOrFail($draft['note_id']);
        $this->assertSame(DocumentTypeEnum::DEBIT_NOTE, $note->document_type);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $note->document_status);
        $this->assertStringStartsWith('DBN', $note->invoice_number);
        $this->assertSame(50.0, (float) $note->balance_due_native);
        $this->assertSame(1000.0, (float) $invoice->fresh()->balance_due_native);
    }

    private function invoiceWithApprover(): Invoice
    {
        $customer = $this->seedTestOrganization('Legacy Note Customer');
        $customer->set(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, static::$cachedUser->email);

        return $this->issueTestInvoice($customer, 1000.0);
    }

    private function approve(int $noteId): void
    {
        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(target_type: 'invoice', target_id: $noteId);

        $this->assertTrue($result['approved']);
    }
}
