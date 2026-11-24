<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Exceptions\ApprovalRequiredException;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\PollEcfResultJob;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;
use Tests\Stubs\Scribe\FakeEcfProvider;

class EcfIssuanceFlowTest extends EcfTestCase
{
    public function test_a_person_issuing_takes_the_next_e_ncf_and_queues_the_submission(): void
    {
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);

        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame('E310000000004', $invoice->invoice_number);

        $ecf = EcfCompliance::read($invoice);
        $this->assertSame('E310000000004', $ecf['encf']);
        $this->assertSame(EcfTypeEnum::CREDITO_FISCAL->value, $ecf['type']);
        $this->assertSame('2028-12-31', $ecf['expires_at']);
        $this->assertSame('fake', $ecf['provider']);
        $this->assertSame(EcfStatusEnum::WAITING->value, $ecf['status']);

        Queue::assertPushed(SubmitEcfJob::class, fn (SubmitEcfJob $job): bool => $job->fiscalDocument->entity->is($invoice));
    }

    public function test_an_accepted_submission_records_the_dgii_fields_and_the_ledger_event(): void
    {
        FakeEcfProvider::$pdfBase64 = null;
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);

        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        $invoice->refresh();
        $ecf = EcfCompliance::read($invoice);
        $this->assertSame(EcfStatusEnum::ACCEPTED->value, $ecf['status']);
        $this->assertSame('ABC123', $ecf['security_code']);
        $this->assertCount(2, $ecf['qr_legends']);
        $this->assertSame('fake-' . EcfCompliance::current($invoice)->latestSubmission->system_ref, $ecf['provider_ref']);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame('E310000000004', FakeEcfProvider::$submitted[0]->encf);
        $this->assertTrue($this->ledgerHas($invoice, 'scribe.invoice.ecf_accepted'));
    }

    public function test_a_rejected_e_ncf_is_burned_and_the_invoice_voided_so_the_books_net_to_zero(): void
    {
        FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
        FakeEcfProvider::$messages = ['RNC del comprador no válido'];
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);

        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        $invoice->refresh();
        $ecf = EcfCompliance::read($invoice);
        $this->assertSame(EcfStatusEnum::REJECTED->value, $ecf['status']);
        $this->assertTrue($ecf['burned']);
        $this->assertSame(['RNC del comprador no válido'], $ecf['messages']);
        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $invoice->document_status);
        $this->assertSame('ecf_rejected', $invoice->void_reason_code);
        $this->assertTrue($this->ledgerHas($invoice, 'scribe.invoice.ecf_rejected'));

        $next = $this->issue($this->draftInvoice($customer), $customer);
        $this->assertSame('E310000000005', $next->invoice_number, 'The rejected number is never reused.');
    }

    public function test_a_waiting_answer_schedules_a_poll_and_the_poll_records_the_final_status(): void
    {
        FakeEcfProvider::$submitStatus = EcfStatusEnum::WAITING;
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);

        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        Queue::assertPushed(PollEcfResultJob::class, fn (PollEcfResultJob $job): bool => $job->attempt === 1);

        new PollEcfResultJob($this->kanvasApp, EcfCompliance::current($invoice), 1)->handle();

        $this->assertSame(EcfStatusEnum::ACCEPTED, EcfCompliance::status($invoice->refresh()));
        $this->assertCount(1, FakeEcfProvider::$fetched);
    }

    public function test_polling_gives_up_with_an_agent_visible_flag(): void
    {
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);

        PollEcfResultJob::schedule(EcfCompliance::current($invoice), PollEcfResultJob::MAX_ATTEMPTS + 1);

        $this->assertTrue(EcfCompliance::read($invoice->refresh())['poll_exhausted']);
        $this->assertTrue($this->ledgerHas($invoice, 'scribe.invoice.ecf_result_overdue'));
    }

    public function test_a_repeated_final_answer_is_a_no_op(): void
    {
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        FakeEcfProvider::$fetchStatus = EcfStatusEnum::WAITING;
        new PollEcfResultJob($this->kanvasApp, EcfCompliance::current($invoice), 1)->handle();

        $this->assertSame(EcfStatusEnum::ACCEPTED, EcfCompliance::status($invoice->refresh()));
    }

    public function test_an_issued_e_cf_cannot_be_voided(): void
    {
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);

        $this->expectException(InvalidInvoiceTransitionException::class);
        $this->expectExceptionMessage('credit note');

        new VoidInvoiceAction(invoice: $invoice, voidReasonCode: 'customer_request')->execute();
    }

    public function test_an_invalid_document_is_refused_before_a_number_is_spent(): void
    {
        $customer = $this->customer();
        $draft = $this->draftInvoice($customer, null, [[300000.0, 54000.0]]);

        try {
            $this->issue($draft, $customer);
            $this->fail('E32 at or above DOP 250,000 needs the buyer tax id.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('E32', $e->getMessage());
        }

        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $draft->refresh()->document_status);
        $this->assertNull($draft->invoice_number);

        $next = $this->issue($this->draftInvoice($customer, null), $customer);
        $this->assertSame('E320000000004', $next->invoice_number, 'The refused document consumed no number.');
    }

    public function test_an_expired_range_refuses_the_issue(): void
    {
        $this->configureRange(
            EcfTypeEnum::CREDITO_FISCAL,
            4,
            1000,
            '2026-05-31'
        );
        $customer = $this->customer();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('expired');

        $this->issue($this->draftInvoice($customer), $customer);
    }

    public function test_an_agent_cannot_issue_an_e_cf_without_approval(): void
    {
        $customer = $this->customer();
        $draft = $this->draftInvoice($customer);

        $this->expectException(ApprovalRequiredException::class);

        ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn () => $this->issue($draft, $customer)
        );
    }

    public function test_a_credit_note_from_a_person_gets_an_e34_referencing_the_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        $creditNote = new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: $this->creditNoteData(
                $customer,
                500.0,
                90.0,
                'CNR-77'
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame('E340000000004', $creditNote->invoice_number);
        $this->assertSame('CNR-77', $creditNote->metadata['reference_number']);
        $ecf = EcfCompliance::read($creditNote);
        $this->assertSame('E310000000004', $ecf['references']['encf']);
        $this->assertSame(3, $ecf['references']['modificationCode']);
        Queue::assertPushed(SubmitEcfJob::class, fn (SubmitEcfJob $job): bool => $job->fiscalDocument->entity->is($creditNote));
    }

    public function test_an_agent_cannot_issue_a_credit_note_directly_but_can_draft_one(): void
    {
        $customer = $this->customer();
        $invoice = $this->issue($this->draftInvoice($customer), $customer);
        $action = new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: $this->creditNoteData($customer, 1000.0, 180.0),
            user: static::$cachedUser,
        );

        ApprovalOriginService::during(ApprovalOriginEnum::AGENT, function () use ($action): void {
            try {
                $action->execute();
                $this->fail('An agent must not issue an e-CF credit note directly.');
            } catch (ApprovalRequiredException) {
            }

            $draft = $action->draft();
            $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $draft->document_status);
            $this->assertNull($draft->invoice_number);
        });
    }

    private function issue(Invoice $draft, Organization $customer): Invoice
    {
        return new IssueInvoiceAction(
            invoice: $draft,
            billable: $customer,
            user: static::$cachedUser,
        )->execute();
    }

    private function creditNoteData(
        Organization $customer,
        float $amount,
        float $tax,
        ?string $number = null
    ): InvoiceData {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: $customer,
            lines: new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(
                    description: 'Devolución parcial',
                    quantity: 1,
                    unit_price_native: $amount,
                    tax_rate: 18.0,
                    tax_amount_native: $tax,
                ),
            ]),
            currency: 'DOP',
            fx_rate_to_base: 1.0,
            invoice_number: $number,
        );
    }

    private function ledgerHas(Invoice $invoice, string $eventType): bool
    {
        return Event::query()
            ->where('event_type', $eventType)
            ->where('source_entity_type', Invoice::class)
            ->where('source_entity_id', $invoice->getId())
            ->exists();
    }
}
