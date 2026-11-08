<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DraftCreditNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DraftDebitNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\IssuePurchaseEcfTool;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfDocumentBuilder;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * The agent's side of 5.7 and 4.5: draft_credit_note / draft_debit_note against one invoice and
 * issue_purchase_ecf, all parked for a person; plus the E45/E46 fields the builder reads off the draft.
 */
class EcfCompletionToolsTest extends EcfCompletionTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->artisan('kanvas-scribe:seed-ecf-approval-policy', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();
    }

    public function test_draft_credit_note_parks_an_e34_draft_for_approval_against_the_invoice(): void
    {
        $invoice = $this->acceptedInvoice();

        $result = new DraftCreditNoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)(
            invoice_id: $invoice->getId(),
            lines: [['description' => 'Descuento', 'unit_price' => 100, 'tax_amount' => 18, 'tax_rate' => 18]],
            reason: 'Descuento acordado',
        );

        $this->assertTrue($result['drafted']);
        $this->assertTrue($result['pending_approval']);
        $this->assertNull($result['encf']);

        $note = Invoice::query()->findOrFail($result['note_id']);
        $this->assertSame(DocumentTypeEnum::CREDIT_NOTE, $note->document_type);
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $note->document_status);
        $this->assertSame($invoice->getId(), $note->parent_invoice_id);
        $this->assertSame(ApprovalOriginEnum::AGENT, $note->pendingApproval()->origin);
        $this->assertSame('approve_credit_note', $note->pendingApproval()->approval_type);

        $again = new DraftCreditNoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)(
            invoice_id: $invoice->getId(),
            lines: [['description' => 'Descuento', 'unit_price' => 100, 'tax_amount' => 18, 'tax_rate' => 18]],
            reason: 'Descuento acordado',
        );

        $this->assertFalse($again['drafted']);
        $this->assertSame('draft_already_waiting', $again['reason']);
        $this->assertSame($note->getId(), $again['note_id']);
        $this->assertSame(1, Invoice::query()->where('parent_invoice_id', $invoice->getId())->count(), 'no second draft');
    }

    public function test_draft_debit_note_parks_an_e33_draft_and_refuses_a_bad_line(): void
    {
        $invoice = $this->acceptedInvoice();
        $tool = new DraftDebitNoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $refused = $tool(invoice_id: $invoice->getId(), lines: [['description' => 'x', 'unit_price' => -5]], reason: 'Cargo');
        $this->assertFalse($refused['drafted']);
        $this->assertSame('invalid_line', $refused['reason']);

        $result = $tool(invoice_id: $invoice->getId(), lines: [['description' => 'Flete', 'unit_price' => 200, 'tax_amount' => 36]], reason: 'Flete no facturado');

        $this->assertTrue($result['drafted']);
        $this->assertSame('debit_note', $result['document_type']);
        $this->assertSame('approve_debit_note', Invoice::query()->findOrFail($result['note_id'])->pendingApproval()->approval_type);
    }

    public function test_draft_note_tools_refuse_an_invoice_of_another_company(): void
    {
        $result = new DraftCreditNoteTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser)(
            invoice_id: 999999999,
            lines: [['description' => 'x', 'unit_price' => 1]],
            reason: 'x',
        );

        $this->assertFalse($result['drafted']);
        $this->assertSame('invoice_not_found', $result['reason']);
    }

    public function test_issue_purchase_ecf_requests_approval_and_never_issues(): void
    {
        $bill = $this->receivedBill();
        $tool = new IssuePurchaseEcfTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $this->assertSame('one_document', $tool(ecf_type: '41')['reason']);
        $this->assertSame('invalid_type', $tool(ecf_type: '31', bill_id: $bill->getId())['reason']);

        $result = $tool(ecf_type: 'E41', bill_id: $bill->getId());

        $this->assertTrue($result['requested']);
        $this->assertTrue($result['pending_approval']);
        $this->assertNull($result['encf']);
        $this->assertNotNull($bill->refresh()->pendingApproval(EcfIssuanceService::PURCHASE_APPROVAL_TYPE));
    }

    public function test_the_builder_reads_the_government_purchase_order_and_the_export_block_off_the_draft(): void
    {
        $customer = $this->customer();

        $government = $this->draftInvoice($customer);
        $government->regional_compliance = ['do_ecf' => ['type' => '45', 'purchase_order_number' => ' OC-77 ']];
        $government->save();

        $export = $this->draftInvoice($customer);
        $export->regional_compliance = ['do_ecf' => ['type' => '46', 'export' => ['incoterm' => 'cif', 'destination_country' => 'pr', 'estimated_foreign_value' => 900]]];
        $export->save();

        $builder = new EcfDocumentBuilder();
        $this->assertSame('OC-77', $builder->build($government)->purchaseOrderNumber);
        $this->assertNull($builder->build($government)->export);

        $document = $builder->build($export);
        $this->assertSame('CIF', $document->export->incoterm);
        $this->assertSame('PR', $document->export->destinationCountry);
        $this->assertSame(900.0, $document->export->estimatedForeignValue);
        $this->assertFalse(EcfCompliance::hasNumber($export));
    }
}
