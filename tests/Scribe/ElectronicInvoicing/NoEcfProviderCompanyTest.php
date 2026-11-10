<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * A company without `scribe-ecf-provider` must behave exactly as before e-CF existed.
 */
class NoEcfProviderCompanyTest extends EcfTestCase
{
    protected function companyIssuesEcf(): bool
    {
        return false;
    }

    public function test_issuing_keeps_plain_numbering_and_sends_nothing(): void
    {
        $customer = $this->customer();
        $invoice = new IssueInvoiceAction($this->draftInvoice($customer), $customer, static::$cachedUser)->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame('1', $invoice->invoice_number);
        $this->assertFalse(EcfCompliance::hasNumber($invoice));
        Queue::assertNotPushed(SubmitEcfJob::class);
    }

    public function test_an_agent_is_not_gated_and_a_void_still_works(): void
    {
        $customer = $this->customer();
        $draft = $this->draftInvoice($customer);

        $invoice = ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Invoice => new IssueInvoiceAction($draft, $customer, static::$cachedUser)->execute()
        );
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);

        $voided = new VoidInvoiceAction(invoice: $invoice, voidReasonCode: 'customer_request')->execute();
        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $voided->document_status);
    }
}
