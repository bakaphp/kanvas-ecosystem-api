<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateInvoicePdfTool;
use Kanvas\Scribe\Delivery\Services\DocumentFilesService;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum as SequenceDocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfPdfSourceEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;

/**
 * An e-NCF the provider holds for another document never reached DGII under our invoice: the invoice is voided,
 * never corrected with a note, and no PDF of it is presented as a fiscal document.
 */
class EcfNumberHeldElsewhereTest extends EcfCompletionTestCase
{
    public function test_a_note_against_a_number_held_elsewhere_is_refused_and_says_to_void(): void
    {
        $invoice = $this->heldElsewhereInvoice();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Void that invoice instead');

        $this->creditNoteFor($invoice);
    }

    public function test_a_note_against_an_e_cf_dgii_has_not_answered_is_refused(): void
    {
        $invoice = $this->waitingInvoice();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('DGII has not accepted (draft); a note can only modify an accepted one');

        $this->creditNoteFor($invoice);
    }

    public function test_a_note_is_refused_when_drafted_without_a_dgii_range_for_its_type(): void
    {
        $invoice = $this->acceptedInvoice();
        DocumentSequence::query()
            ->where('companies_id', $this->company->getId())
            ->where('document_type', SequenceDocumentTypeEnum::forFiscal(EcfTypeEnum::NOTA_CREDITO)->value)
            ->delete();
        $before = Invoice::query()->where('parent_invoice_id', $invoice->getId())->count();

        try {
            $this->creditNoteFor($invoice);
            $this->fail('A credit note with no E34 range must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no usable E34 range', $e->getMessage());
        }

        $this->assertSame($before, Invoice::query()->where('parent_invoice_id', $invoice->getId())->count(), 'nothing was drafted');
    }

    public function test_an_invoice_whose_number_is_held_elsewhere_can_be_voided(): void
    {
        $invoice = $this->heldElsewhereInvoice();

        $voided = new VoidInvoiceAction(invoice: $invoice, voidReasonCode: 'number_used_elsewhere', user: static::$cachedUser)->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $voided->document_status);
    }

    public function test_an_accepted_e_cf_still_cannot_be_voided(): void
    {
        $invoice = $this->acceptedInvoice();

        $this->expectExceptionMessage('Issue a credit note (E34)');

        new VoidInvoiceAction(invoice: $invoice, voidReasonCode: 'test', user: static::$cachedUser)->execute();
    }

    public function test_the_pdf_tool_returns_the_legal_e_cf_pdf_and_never_renders_a_plain_one(): void
    {
        $invoice = $this->acceptedInvoice();
        $legal = $this->createFilesystemRow(companiesId: $this->company->getId());
        $invoice->addFile($legal, ApplyFiscalAnswerAction::PDF_FIELD_NAME);

        $result = $this->pdfTool()->__invoke(invoice_id: $invoice->getId());

        $this->assertTrue($result['generated']);
        $this->assertTrue($result['is_ecf']);
        $this->assertSame($legal->getId(), $result['filesystem_id']);
        $this->assertNull($invoice->refresh()->getFileByName(DocumentPdfService::INVOICE_FIELD_NAME));
    }

    public function test_with_our_layout_chosen_the_pdf_tool_renders_ours_beside_the_providers_copy(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }

        $invoice = $this->acceptedInvoice();
        $provider = $this->createFilesystemRow(companiesId: $this->company->getId());
        $invoice->addFile($provider, ApplyFiscalAnswerAction::PDF_FIELD_NAME);
        $this->company->set(ConfigurationEnum::ECF_PDF_SOURCE->value, EcfPdfSourceEnum::KANVAS->value);

        $result = $this->pdfTool()->__invoke(invoice_id: $invoice->getId());

        $ours = $invoice->refresh()->getFileByName(FiscalRegimeInterface::INVOICE_PDF_FIELD)?->filesystem;
        $this->assertNotNull($ours);
        $this->assertSame($ours->getId(), $result['filesystem_id']);
        $this->assertNotSame($provider->getId(), $result['filesystem_id']);
        $this->assertSame($ours->getId(), new DocumentFilesService()->legalInvoicePdf($invoice)->getId(), 'delivery sends ours too');

        $again = $this->pdfTool()->__invoke(invoice_id: $invoice->getId());
        $this->assertSame($ours->getId(), $again['filesystem_id'], 'rendered once, then reused');
    }

    public function test_the_pdf_tool_refuses_an_e_cf_dgii_has_not_accepted(): void
    {
        $invoice = $this->waitingInvoice();

        $result = $this->pdfTool()->__invoke(invoice_id: $invoice->getId());

        $this->assertFalse($result['generated']);
        $this->assertSame('ecf_not_accepted', $result['reason']);
        $this->assertStringContainsString('do not offer a plain PDF', $result['message']);
        $this->assertNull($invoice->refresh()->getFileByName(DocumentPdfService::INVOICE_FIELD_NAME));
    }

    private function waitingInvoice(): Invoice
    {
        $customer = $this->customer();

        return new IssueInvoiceAction(
            invoice: $this->draftInvoice($customer),
            billable: $customer,
            user: static::$cachedUser,
        )->execute();
    }

    private function heldElsewhereInvoice(): Invoice
    {
        $invoice = $this->waitingInvoice();
        EcfCompliance::current($invoice)->flagNumberHeldElsewhere('held by another document');

        return $invoice->refresh();
    }

    private function creditNoteFor(Invoice $invoice): Invoice
    {
        return new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $invoice->customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Reverso',
                        quantity: 1.0,
                        unit_price_native: 100.0,
                        tax_rate: 18.0,
                        tax_amount_native: 18.0,
                    ),
                ]),
                currency: 'DOP',
                fx_rate_to_base: 1.0,
            ),
            billable: $invoice->customer,
            user: static::$cachedUser,
        )->execute();
    }

    private function pdfTool(): GenerateInvoicePdfTool
    {
        return new GenerateInvoicePdfTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
