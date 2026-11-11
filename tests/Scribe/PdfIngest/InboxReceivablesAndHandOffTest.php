<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RecentInboxArrivalsTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;
use Tests\Scribe\Setup\Concerns\UsesFreshAccountingCompany;

/**
 * Customer documents reach the AR agent through the same accounting inbox (decision 22), and whatever
 * the inbox could not finish becomes an agent's task (plan 0.4).
 */
final class InboxReceivablesAndHandOffTest extends ScribeTestCase
{
    use UsesFreshAccountingCompany;

    public function test_a_remittance_is_matched_to_the_open_invoice_and_handed_to_the_ar_agent_without_applying_it(): void
    {
        $ar = $this->accountingAgent($this->company, AccountsReceivableAgent::class);
        $customer = $this->seedTestOrganization('Wayne Remit ' . uniqid());
        $invoice = $this->issueTestInvoice($customer, 500.0);

        $log = $this->ingest(PdfIngestDocumentTypeEnum::REMITTANCE_ADVICE, [
            'customer_name' => $customer->name,
            'total' => 500.0,
            'currency' => 'USD',
            'payment_reference' => 'TRX-889',
            'referenced_invoice_numbers' => [$invoice->invoice_number],
        ]);

        $this->assertSame(PdfIngestStatusEnum::ROUTED_TO_AR, $log->status);
        $routing = $log->extracted_payload['ar_routing'];
        $this->assertSame($invoice->invoice_number, $routing['remittance']['exact_match']);
        $this->assertSame($ar->getId(), $routing['agent_id']);
        $this->assertSame(500.0, (float) $invoice->refresh()->balance_due_native);

        $this->assertTrue(Event::query()
            ->where('event_type', 'scribe.invoice.remittance_received')
            ->where('source_entity_type', Invoice::class)
            ->where('source_entity_id', $invoice->getId())
            ->exists());
        $task = $this->taskFor($ar);
        $this->assertStringContainsString($invoice->invoice_number, (string) $task->payload['instruction']);
        $this->assertStringContainsString('Do not record the payment', (string) $task->payload['instruction']);
        $this->assertSame('routed_to_ar', $this->outcome($log));
    }

    public function test_a_customer_po_becomes_an_ar_task_to_draft_the_quote_or_invoice(): void
    {
        $ar = $this->accountingAgent($this->company, AccountsReceivableAgent::class);

        $log = $this->ingest(PdfIngestDocumentTypeEnum::CUSTOMER_PO, [
            'customer_name' => 'Stark Buying',
            'po_number' => 'PO-7781',
            'total' => 1200.0,
            'currency' => 'USD',
        ]);

        $this->assertSame(PdfIngestStatusEnum::ROUTED_TO_AR, $log->status);
        $instruction = (string) $this->taskFor($ar)->payload['instruction'];
        $this->assertStringContainsString('PO-7781', $instruction);
        $this->assertStringContainsString('create_quote', $instruction);
    }

    public function test_a_credit_request_becomes_an_ar_task_that_never_grants_credit_itself(): void
    {
        $ar = $this->accountingAgent($this->company, AccountsReceivableAgent::class);

        $log = $this->ingest(PdfIngestDocumentTypeEnum::CREDIT_REQUEST, ['customer_name' => 'Oscorp']);

        $this->assertSame(PdfIngestStatusEnum::ROUTED_TO_AR, $log->status);
        $instruction = (string) $this->taskFor($ar)->payload['instruction'];
        $this->assertStringContainsString('extract_credit_request_form', $instruction);
        $this->assertStringContainsString('Do not grant credit', $instruction);
    }

    public function test_without_an_ar_agent_the_document_is_routed_with_the_reason_and_nobody_is_woken(): void
    {
        $company = $this->freshCompany();

        $log = $this->ingest(PdfIngestDocumentTypeEnum::CREDIT_REQUEST, ['customer_name' => 'Nobody Corp'], $company);

        $this->assertSame(PdfIngestStatusEnum::ROUTED_TO_AR, $log->status);
        $this->assertStringContainsString('no AR agent', (string) $log->rejected_reason);
        $this->assertSame(0, ScheduledAction::query()->where('companies_id', $company->getId())->count());
    }

    public function test_a_bill_left_for_review_becomes_an_ap_task_with_what_to_fix(): void
    {
        $ap = $this->accountingAgent($this->company, AccountsPayableAgent::class);
        $email = 'nogl-' . uniqid() . '@vendor.test';

        $log = $this->ingest(PdfIngestDocumentTypeEnum::VENDOR_INVOICE, [
            'vendor_name' => 'No GL Vendor ' . uniqid(),
            'vendor_email' => $email,
            'bill_number' => 'INV-' . uniqid(),
            'currency' => 'USD',
            'total' => 80.0,
            'subtotal' => 80.0,
        ]);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $task = $this->taskFor($ap);
        $this->assertSame(Bill::class, $task->source_entity_type);
        $this->assertSame((string) $log->linked_entity_id, (string) $task->source_entity_id);
        $this->assertStringContainsString('expense account', (string) $task->payload['instruction']);
    }

    public function test_a_bill_that_went_to_approval_wakes_nobody(): void
    {
        $ap = $this->accountingAgent($this->company, AccountsPayableAgent::class);

        $log = $this->ingest(PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT, [
            'vendor_name' => 'Coffee Shop',
            'currency' => 'USD',
            'total' => 12.0,
            'subtotal' => 12.0,
        ]);

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertFalse(ScheduledAction::query()->where('agent_id', $ap->getId())->exists());
    }

    public function test_recent_inbox_arrivals_lists_outcomes_and_filters_by_one(): void
    {
        $this->accountingAgent($this->company, AccountsReceivableAgent::class);
        $routed = $this->ingest(PdfIngestDocumentTypeEnum::CREDIT_REQUEST, ['customer_name' => 'Listed Corp ' . uniqid()]);
        $this->ingest(PdfIngestDocumentTypeEnum::UNKNOWN, []);

        $tool = new RecentInboxArrivalsTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
        $all = $tool->__invoke(days: 3);
        $onlyAr = $tool->__invoke(outcome: 'routed_to_ar', limit: 100);

        $this->assertGreaterThanOrEqual(2, $all['count']);
        $this->assertContains((int) $routed->filesystem_id, array_column($onlyAr['arrivals'], 'filesystem_id'));
        $this->assertSame(['routed_to_ar'], array_values(array_unique(array_column($onlyAr['arrivals'], 'outcome'))));
        $this->assertArrayHasKey('error', $tool->__invoke(outcome: 'bogus'));
    }

    /**
     * @param array<string, mixed> $extracted
     */
    private function ingest(
        PdfIngestDocumentTypeEnum $type,
        array $extracted,
        ?Companies $company = null,
    ): PdfIngestLog {
        $company ??= $this->company;

        return new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $company,
                pdf: $this->createFilesystemRow(companiesId: $company->getId()),
                messageId: 'ar-inbox-' . uniqid(),
                fromEmail: 'docs-' . uniqid() . '@customer.test',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: $type,
                confidence: 0.9,
                extracted: $extracted,
            )),
            hasher: new FakePdfContentHasher(),
        )->execute();
    }

    private function taskFor(Agent $agent): ScheduledAction
    {
        return ScheduledAction::query()->where('agent_id', $agent->getId())->latest('id')->firstOrFail();
    }

    private function outcome(PdfIngestLog $log): ?string
    {
        return Event::query()
            ->where('event_type', 'scribe.document.ingested')
            ->where('source_entity_id', $log->filesystem_id)
            ->latest('id')
            ->first()?->payload['outcome'];
    }
}
