<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfDocumentBuilder;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\UpdateInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Spatie\LaravelData\DataCollection;

/**
 * Plan PR 5.7 / decision 3: an edit to an issued e-CF never touches the invoice; the difference goes out
 * as an E34 (credit) and/or E33 (debit) note, issued as the person, or drafted for approval for an agent.
 */
class EcfCorrectionTest extends EcfCompletionTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->artisan('kanvas-scribe:seed-ecf-approval-policy', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();
    }

    public function test_lowering_a_price_issues_a_partial_credit_note_and_leaves_the_invoice_as_issued(): void
    {
        $invoice = $this->acceptedInvoice();

        $returned = $this->edit($invoice, [['Consultoría', 1, 500.0, 90.0], ['Soporte', 1, 400.0, 72.0]]);

        $this->assertSame(1180.0, (float) $returned->total_native, 'The issued e-CF is never mutated.');
        $this->assertSame('E310000000004', $returned->invoice_number);
        $this->assertCount(2, $returned->lines);

        $creditNote = $returned->creditNotes()->sole();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $creditNote->document_status);
        $this->assertSame('E340000000004', $creditNote->invoice_number);
        $this->assertSame(118.0, (float) $creditNote->total_native);
        $this->assertSame(18.0, (float) $creditNote->tax_native);
        $this->assertSame('Consultoría', $creditNote->lines()->sole()->description);

        $reference = EcfCompliance::read($creditNote)['references'];
        $this->assertSame('E310000000004', $reference['encf']);
        $this->assertSame(EcfDocumentBuilder::MODIFICATION_AMOUNTS, $reference['modificationCode']);
        $this->assertSame(1062.0, (float) $returned->refresh()->balance_due_native);
        $this->assertSame(0, $returned->debitNotes()->count());
    }

    public function test_removing_every_line_cancels_the_invoice_with_a_full_credit_note(): void
    {
        $invoice = $this->acceptedInvoice();

        $returned = $this->edit($invoice, []);

        $creditNote = $returned->creditNotes()->sole();
        $this->assertSame(1180.0, (float) $creditNote->total_native);
        $this->assertCount(2, $creditNote->lines);
        $this->assertSame(EcfDocumentBuilder::MODIFICATION_CANCELS, EcfCompliance::read($creditNote)['references']['modificationCode']);
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $returned->refresh()->document_status);
    }

    public function test_adding_a_line_issues_a_debit_note_with_its_own_receivable(): void
    {
        $invoice = $this->acceptedInvoice();

        $returned = $this->edit($invoice, [
            ['Consultoría', 1, 600.0, 108.0],
            ['Soporte', 1, 400.0, 72.0],
            ['Viáticos', 2, 150.0, 54.0],
        ]);

        $debitNote = $returned->debitNotes()->sole();
        $this->assertSame(DocumentTypeEnum::DEBIT_NOTE, $debitNote->document_type);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $debitNote->document_status);
        $this->assertSame('E330000000001', $debitNote->invoice_number);
        $this->assertSame(354.0, (float) $debitNote->total_native);
        $this->assertSame(354.0, (float) $debitNote->balance_due_native);
        $this->assertSame(2.0, (float) $debitNote->lines()->sole()->quantity);

        $ecf = EcfCompliance::read($debitNote);
        $this->assertSame(EcfTypeEnum::NOTA_DEBITO->value, $ecf['type']);
        $this->assertSame('E310000000004', $ecf['references']['encf']);
        $this->assertSame(EcfDocumentBuilder::MODIFICATION_AMOUNTS, $ecf['references']['modificationCode']);

        $je = JournalEntry::query()->where('source_type', 'debit_note')->where('source_id', $debitNote->getId())->sole();
        $this->assertEqualsWithDelta(354.0, (float) $je->lines()->sum('debit_native'), 0.001);
        $this->assertEqualsWithDelta(354.0, (float) $je->lines()->sum('credit_native'), 0.001);
        $this->assertSame(0, $returned->creditNotes()->count());
    }

    public function test_one_edit_that_lowers_one_line_and_raises_another_issues_one_note_of_each(): void
    {
        $invoice = $this->acceptedInvoice();

        $returned = $this->edit($invoice, [['Consultoría', 1, 500.0, 90.0], ['Soporte', 2, 400.0, 144.0]]);

        $this->assertSame(118.0, (float) $returned->creditNotes()->sole()->total_native);
        $this->assertSame(472.0, (float) $returned->debitNotes()->sole()->total_native);
    }

    public function test_a_second_edit_corrects_from_the_already_corrected_state(): void
    {
        $invoice = $this->acceptedInvoice();
        $this->edit($invoice, [['Consultoría', 1, 500.0, 90.0], ['Soporte', 1, 400.0, 72.0]]);

        $returned = $this->edit($invoice->refresh(), [['Consultoría', 1, 450.0, 81.0], ['Soporte', 1, 400.0, 72.0]]);

        $totals = $returned->creditNotes()->orderBy('id')->pluck('total_native')->map(fn ($total): float => (float) $total)->all();
        $this->assertSame([118.0, 59.0], $totals);
    }

    public function test_an_agent_edit_drafts_the_note_for_approval_and_it_issues_when_a_person_approves(): void
    {
        $invoice = $this->acceptedInvoice();

        $returned = ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Invoice => $this->edit($invoice, [['Consultoría', 1, 500.0, 90.0], ['Soporte', 1, 400.0, 72.0]])
        );

        $creditNote = $returned->creditNotes()->sole();
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $creditNote->document_status);
        $this->assertFalse(EcfCompliance::hasNumber($creditNote), 'No E34 is spent before a person approves.');

        $request = $creditNote->pendingApproval();
        $this->assertSame('approve_credit_note', $request->approval_type);

        new ApproveAction($request, static::$cachedUser)->execute();

        $this->assertSame('E340000000004', $creditNote->refresh()->invoice_number);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $creditNote->document_status);
    }

    public function test_an_agent_debit_note_waits_on_the_debit_note_policy(): void
    {
        $invoice = $this->acceptedInvoice();

        $returned = ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Invoice => $this->edit($invoice, [['Consultoría', 1, 700.0, 126.0], ['Soporte', 1, 400.0, 72.0]])
        );

        $debitNote = $returned->debitNotes()->sole();
        $this->assertSame('approve_debit_note', $debitNote->pendingApproval()->approval_type);

        new ApproveAction($debitNote->pendingApproval(), static::$cachedUser)->execute();

        $this->assertSame('E330000000001', $debitNote->refresh()->invoice_number);
    }

    public function test_an_edit_that_changes_no_amount_is_refused(): void
    {
        $invoice = $this->acceptedInvoice();

        $this->expectException(InvalidInvoiceTransitionException::class);
        $this->edit($invoice, [['Consultoría', 1, 600.0, 108.0], ['Soporte', 1, 400.0, 72.0]]);
    }

    public function test_an_e_cf_still_waiting_for_dgii_cannot_be_corrected_yet(): void
    {
        $customer = $this->customer();
        $invoice = new IssueInvoiceAction(
            invoice: $this->draftInvoice($customer),
            billable: $customer,
            user: static::$cachedUser,
        )->execute();
        $this->assertSame(EcfStatusEnum::WAITING, EcfCompliance::status($invoice));

        $this->expectException(InvalidInvoiceTransitionException::class);
        $this->expectExceptionMessage('waiting');
        $this->edit($invoice, [['Servicio de consultoría', 1, 500.0, 90.0]]);
    }

    /**
     * @param list<array{0: string, 1: float|int, 2: float, 3: float}> $lines [description, qty, unit price, ITBIS]
     */
    private function edit(Invoice $invoice, array $lines): Invoice
    {
        return new UpdateInvoiceAction(
            invoice: $invoice,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $invoice->customer,
                lines: new DataCollection(InvoiceLineData::class, array_map(
                    fn (array $line): InvoiceLineData => new InvoiceLineData(
                        description: $line[0],
                        quantity: (float) $line[1],
                        unit_price_native: $line[2],
                        tax_rate: $line[3] > 0 ? 18.0 : null,
                        tax_amount_native: $line[3],
                    ),
                    $lines
                )),
                currency: 'DOP',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();
    }
}
