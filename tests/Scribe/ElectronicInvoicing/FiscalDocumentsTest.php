<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\IssuePurchaseEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RecordFiscalEventAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RecordFiscalRangeAnnulmentAction;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RecordFiscalSubmissionAction;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalEventTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalRangeStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalSubmissionStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ImmutableFiscalRecordException;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\ProviderRejectedException;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\PollEcfResultJob;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentRelation;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalRangeAnnulmentLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalArtifactService;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalNumberRangeService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * The fiscal tables as the plan's §9 lists them (docs/accounting/fiscal-documents-table-plan.md).
 */
class FiscalDocumentsTest extends EcfCompletionTestCase
{
    public function test_issuing_one_invoice_twice_takes_one_number_and_the_database_refuses_a_second_current_row(): void
    {
        $invoice = $this->issued();
        $first = EcfCompliance::current($invoice);

        new EcfIssuanceService()->assignNumber($invoice);

        $this->assertSame(1, FiscalDocument::query()->forEntity($invoice)->count());
        $this->assertSame($first->getKey(), EcfCompliance::current($invoice)->getKey());

        $this->expectException(UniqueConstraintViolationException::class);

        FiscalDocument::query()->whereKey($first->getKey())->first()->replicate(['uuid'])->fill([
            'identity_key' => 'other|E310000009999',
            'number' => 'E310000009999',
        ])->save();
    }

    public function test_a_timeout_after_the_provider_accepted_is_unknown_and_resolved_by_query_without_a_second_number(): void
    {
        $invoice = $this->issued();
        FakeEcfProvider::$submitException = new RuntimeException('cURL error 28: Operation timed out');

        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        $fiscal = EcfCompliance::current($invoice);
        $this->assertSame(FiscalDocumentStatusEnum::UNKNOWN, $fiscal->status);
        $this->assertSame(FiscalSubmissionStatusEnum::UNKNOWN, $fiscal->latestSubmission->status);
        Queue::assertPushed(PollEcfResultJob::class, fn (PollEcfResultJob $job): bool => $job->fiscalDocument->is($fiscal));

        new SubmitEcfJob($this->kanvasApp, $fiscal)->handle();
        $this->assertCount(1, FakeEcfProvider::$submitted, 'An unknown send is never sent again.');

        new PollEcfResultJob($this->kanvasApp, $fiscal, 1)->handle();

        $fiscal->refresh();
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $fiscal->status);
        $this->assertSame($fiscal->latestSubmission->system_ref, FakeEcfProvider::$fetched[0]->systemRef, 'A status query asks about the attempt that went out.');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{16}$/', FakeEcfProvider::$fetched[0]->systemRef, 'WebPOS forwards the systemRef as DGII\'s 20-character NumeroFacturaInterna.');
        $this->assertSame('E310000000004', $fiscal->number);
        $this->assertSame(1, FiscalDocument::query()->forEntity($invoice)->count());
    }

    public function test_a_late_answer_for_a_superseded_number_stays_on_that_row(): void
    {
        FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
        $expense = new IssuePurchaseEcfAction($this->approveTestExpense(2500.0), EcfTypeEnum::GASTOS_MENORES)->execute();
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($expense))->handle();
        $burned = EcfCompliance::current($expense);
        $this->assertTrue($burned->isBurned());

        FakeEcfProvider::$submitStatus = EcfStatusEnum::ACCEPTED;
        $expense = new IssuePurchaseEcfAction($expense->refresh(), EcfTypeEnum::GASTOS_MENORES)->execute();
        $current = EcfCompliance::current($expense);
        $this->assertNotSame($burned->getKey(), $current->getKey());
        $this->assertSame('E430000000002', $current->number);
        $this->assertNull($burned->refresh()->current_key);

        new ApplyFiscalAnswerAction(
            fiscalDocument: $burned,
            answer: new EcfSubmission(status: EcfStatusEnum::ACCEPTED, encf: $burned->number),
        )->execute();

        $this->assertTrue($burned->refresh()->needs_review, 'Accepted after rejected is flagged, not flipped.');
        $this->assertSame(FiscalDocumentStatusEnum::REJECTED, $burned->status);
        $this->assertSame(FiscalDocumentStatusEnum::DRAFT, $current->refresh()->status);
        $this->assertSame($current->getKey(), EcfCompliance::current($expense)->getKey());
    }

    public function test_the_issuer_and_receiver_copies_of_one_e_cf_coexist_in_two_tenants(): void
    {
        $this->company->set('scribe-ecf-issuer-rnc', '131794321');
        $invoice = $this->issued();
        $issuedKey = EcfCompliance::current($invoice)->identity_key;

        $other = Companies::factory()->create(['users_id' => static::$cachedUser->getId(), 'is_active' => 1]);
        $other->associateApp($this->kanvasApp);
        $bill = $this->receivedBill();
        $bill->companies_id = $other->getId();
        $bill->saveQuietly();

        $received = $this->fiscalRowFor(
            $bill,
            'E310000000004',
            status: FiscalDocumentStatusEnum::ACCEPTED,
            direction: FiscalDirectionEnum::RECEIVED,
            issuerTaxId: '131794321',
        );

        $this->assertSame($issuedKey, $received->identity_key);
        $this->assertSame('131794321|E310000000004', $issuedKey);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->fiscalRowFor(
            $this->receivedBill(),
            'E310000000004',
            issuerTaxId: '131794321',
        );
    }

    public function test_a_yearly_numbering_regime_accepts_the_same_control_number_in_two_years_but_not_twice_in_one(): void
    {
        $sv = fn (int $year, string $control): array => [
            'country' => 'SV',
            'numbering_year' => $year,
            'identity_key' => "06142803901121|{$year}|{$control}",
        ];

        $this->fiscalRowFor($this->receivedBill(), 'E01SV0000001', attributes: $sv(2026, 'DTE-01-0001P001-000000000000001'));
        $this->fiscalRowFor($this->receivedBill(), 'E01SV0000001', attributes: $sv(2027, 'DTE-01-0001P001-000000000000001'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->fiscalRowFor($this->receivedBill(), 'E01SV0000001', attributes: $sv(2026, 'DTE-01-0001P001-000000000000001'));
    }

    public function test_a_rejection_dgii_left_reusable_keeps_the_invoice_and_resubmits_the_same_number(): void
    {
        FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
        FakeEcfProvider::$numberConsumed = false;
        $invoice = $this->issued();

        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        $fiscal = EcfCompliance::current($invoice);
        $this->assertTrue($fiscal->isReusable());
        $this->assertFalse(EcfCompliance::read($invoice)['burned']);
        $this->assertSame('fix_and_resubmit_ecf', EcfCompliance::read($invoice)['action_required']);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->refresh()->document_status);

        FakeEcfProvider::$submitStatus = EcfStatusEnum::ACCEPTED;
        new SubmitEcfJob($this->kanvasApp, $fiscal)->handle();

        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $fiscal->refresh()->status);
        $this->assertSame(2, $fiscal->submissions()->count());
        $this->assertSame('E310000000004', FakeEcfProvider::$submitted[1]->encf);
        $this->assertSame(1, FiscalDocument::query()->forEntity($invoice)->count());

        $attempts = $fiscal->submissions()->orderBy('attempt')->pluck('system_ref')->all();
        $this->assertSame($attempts, [FakeEcfProvider::$submitted[0]->systemRef, FakeEcfProvider::$submitted[1]->systemRef]);
        $this->assertNotSame($attempts[0], $attempts[1], 'WebPOS answers a repeated systemRef with the old rejection, so a resend needs its own.');
    }

    public function test_issuing_without_an_issuer_rnc_is_refused_before_a_number_is_spent(): void
    {
        $this->company->del(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value);
        $draft = $this->draftInvoice($this->customer());

        try {
            new IssueInvoiceAction(invoice: $draft, billable: $this->customer(), user: static::$cachedUser)->execute();
            $this->fail('DGII requires RncEmisor; an e-CF must not be issued without one.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value, $e->getMessage());
        }

        $this->assertSame(0, FiscalDocument::query()->forEntity($draft)->count());
        $this->company->set(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value, self::ISSUER_RNC);
        $this->assertSame('E310000000004', EcfCompliance::current($this->issued())->number, 'The refused issue spent no number.');
    }

    public function test_a_row_is_answered_in_the_environment_it_was_issued_in_after_the_company_switched(): void
    {
        $invoice = $this->issued();
        $fiscal = EcfCompliance::current($invoice);
        $this->assertSame(EcfEnvironmentEnum::TEST->value, $fiscal->environment);
        FakeEcfProvider::$submitException = new RuntimeException('cURL error 28: Operation timed out');
        new SubmitEcfJob($this->kanvasApp, $fiscal)->handle();

        $this->company->set(EcfConfigurationEnum::ECF_ENVIRONMENT->value, EcfEnvironmentEnum::PRODUCTION->value);
        FakeEcfProvider::$environments = [];

        try {
            new PollEcfResultJob($this->kanvasApp, $fiscal->refresh(), 1)->handle();
        } finally {
            $this->company->del(EcfConfigurationEnum::ECF_ENVIRONMENT->value);
        }

        $this->assertSame([EcfEnvironmentEnum::TEST], FakeEcfProvider::$environments);
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $fiscal->refresh()->status);
    }

    public function test_a_rejection_that_consumed_the_number_or_did_not_say_burns_it_and_voids_the_invoice(): void
    {
        foreach ([true, null] as $consumed) {
            FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
            FakeEcfProvider::$numberConsumed = $consumed;
            $invoice = $this->issued();

            new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

            $fiscal = EcfCompliance::current($invoice);
            $this->assertTrue($fiscal->isBurned());
            $this->assertSame($consumed, $fiscal->authority_number_consumed);
            $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $invoice->refresh()->document_status);
        }
    }

    public function test_a_provider_validation_failure_keeps_the_row_a_draft_with_the_same_number(): void
    {
        FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
        FakeEcfProvider::$reachedAuthority = false;
        $invoice = $this->issued();

        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        $fiscal = EcfCompliance::current($invoice);
        $this->assertSame(FiscalDocumentStatusEnum::DRAFT, $fiscal->status);
        $this->assertNull($fiscal->frozen_at, 'Nothing reached the authority, so the snapshot may still follow a fix.');
        $this->assertSame(FiscalSubmissionStatusEnum::PROVIDER_REJECTED, $fiscal->latestSubmission->status);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->refresh()->document_status);
    }

    public function test_a_duplicate_cancellation_or_webhook_is_one_event(): void
    {
        $invoice = $this->accept($this->issued());
        $fiscal = EcfCompliance::current($invoice);
        $cancelled = new EcfSubmission(status: EcfStatusEnum::CANCELLED, encf: $fiscal->number);

        new ApplyFiscalAnswerAction(fiscalDocument: $fiscal, answer: $cancelled)->execute();
        new ApplyFiscalAnswerAction(fiscalDocument: $fiscal, answer: $cancelled)->execute();

        $this->assertSame(FiscalDocumentStatusEnum::CANCELLED, $fiscal->refresh()->status);
        $this->assertSame(1, $fiscal->events()->where('status', FiscalDocumentStatusEnum::CANCELLED->value)->count());
        $this->assertTrue($this->ledgerHas($invoice, 'scribe.invoice.ecf_cancelled'));
    }

    public function test_an_answer_older_than_the_last_applied_is_recorded_but_not_applied(): void
    {
        $invoice = $this->issued();
        $fiscal = EcfCompliance::current($invoice);

        new ApplyFiscalAnswerAction(
            fiscalDocument: $fiscal,
            answer: new EcfSubmission(status: EcfStatusEnum::WAITING, encf: $fiscal->number, messages: ['now']),
        )->execute();
        new ApplyFiscalAnswerAction(
            fiscalDocument: $fiscal,
            answer: new EcfSubmission(status: EcfStatusEnum::ACCEPTED, encf: $fiscal->number),
            answeredAt: Carbon::now()->subHour(),
        )->execute();

        $this->assertSame(FiscalDocumentStatusEnum::PENDING, $fiscal->refresh()->status);
        $this->assertSame(2, $fiscal->events()->count());
    }

    public function test_an_acceptance_without_a_qr_takes_the_security_code_from_the_qr_an_earlier_answer_carried(): void
    {
        $fiscal = EcfCompliance::current($this->issued());
        $qrUrl = 'https://ecf.dgii.gov.do/TesteCF/ConsultaTimbre?ENCF=' . $fiscal->number . '&CodigoSeguridad=VyHSrA';

        new ApplyFiscalAnswerAction(
            fiscalDocument: $fiscal,
            answer: new EcfSubmission(status: EcfStatusEnum::WAITING, encf: $fiscal->number, qrUrl: $qrUrl),
        )->execute();
        $this->assertNull($fiscal->refresh()->authority_seal, 'not accepted yet');

        new ApplyFiscalAnswerAction(
            fiscalDocument: $fiscal,
            answer: new EcfSubmission(status: EcfStatusEnum::ACCEPTED_WITH_OBSERVATIONS, encf: $fiscal->number),
        )->execute();

        $this->assertSame('VyHSrA', $fiscal->refresh()->authority_seal);
        $this->assertSame('VyHSrA', EcfCompliance::read($fiscal->entity)['security_code']);
    }

    public function test_an_answer_whose_event_was_saved_but_not_applied_is_applied_on_retry(): void
    {
        $invoice = $this->issued();
        $fiscal = EcfCompliance::current($invoice);
        $answer = new EcfSubmission(status: EcfStatusEnum::ACCEPTED, encf: $fiscal->number, securityCode: 'XYZ789');
        $payload = $answer->toArray();
        unset($payload['pdfBase64'], $payload['wasDuplicated'], $payload['rawResponse']);

        // What a crash right after the event insert leaves behind.
        new RecordFiscalEventAction(
            document: $fiscal,
            type: FiscalEventTypeEnum::AUTHORITY_ANSWER,
            direction: 'received',
            status: FiscalDocumentStatusEnum::ACCEPTED->value,
            dedupeKey: 'answer:' . hash('sha256', $fiscal->uuid . '|inbound|' . json_encode($payload)),
            payload: $payload,
        )->execute();

        new ApplyFiscalAnswerAction(fiscalDocument: $fiscal, answer: $answer)->execute();

        $fiscal->refresh();
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $fiscal->status);
        $this->assertSame('XYZ789', $fiscal->authority_seal);
        $event = $fiscal->events()->sole();
        $this->assertNotNull($event->applied_at);
        $this->assertNotNull($event->effects_completed_at);
        $this->assertTrue($this->ledgerHas($invoice, 'scribe.invoice.ecf_accepted'));
    }

    public function test_a_second_worker_does_not_send_while_an_attempt_is_in_flight_and_the_sweep_turns_it_into_a_query(): void
    {
        $invoice = $this->issued();
        $fiscal = EcfCompliance::current($invoice);
        $fiscal->status = FiscalDocumentStatusEnum::SENDING;
        $fiscal->save();
        $this->submissionFor($fiscal, null)->forceFill([
            'status' => FiscalSubmissionStatusEnum::SENDING,
            'sent_at' => Carbon::now()->subHour(),
        ])->save();
        $fiscal->status = FiscalDocumentStatusEnum::DRAFT;
        $fiscal->save();

        $this->assertNull(new RecordFiscalSubmissionAction($fiscal)->execute());
        $this->assertCount(0, FakeEcfProvider::$submitted);

        $fiscal->status = FiscalDocumentStatusEnum::SENDING;
        $fiscal->save();

        $this->artisan('scribe:fiscal-resolve-stuck-submissions', ['--minutes' => 15])->assertSuccessful();

        $this->assertSame(FiscalDocumentStatusEnum::UNKNOWN, $fiscal->refresh()->status);
        $this->assertSame(FiscalSubmissionStatusEnum::UNKNOWN, $fiscal->latestSubmission->status);
        Queue::assertPushed(PollEcfResultJob::class, fn (PollEcfResultJob $job): bool => $job->fiscalDocument->is($fiscal));
        $this->assertCount(0, FakeEcfProvider::$submitted);
    }

    public function test_a_provider_refusal_keeps_what_the_provider_answered_as_the_response_artifact(): void
    {
        $raw = ['received' => false, 'accepted' => false, 'status' => 1, 'msg' => ''];
        FakeEcfProvider::$submitException = new ProviderRejectedException('WebPOS did not accept the document: no message', $raw);
        $fiscal = EcfCompliance::current($this->issued());

        $attempt = new RecordFiscalSubmissionAction($fiscal, artifacts: new FiscalArtifactService())->execute();

        $this->assertSame(FiscalSubmissionStatusEnum::PROVIDER_REJECTED, $attempt->status);
        $this->assertSame(hash('sha256', (string) json_encode($raw)), $attempt->response_sha256, 'A refusal with no message is otherwise untraceable.');
        $this->assertNotNull($attempt->response_file_id);
        $this->assertSame(FiscalDocumentStatusEnum::DRAFT, $fiscal->refresh()->status);
    }

    public function test_the_request_artifact_is_the_providers_wire_payload_and_nothing_is_sent_without_it(): void
    {
        $invoice = $this->issued();
        $fiscal = EcfCompliance::current($invoice);

        $failing = new class () extends FiscalArtifactService {
            public function store(FiscalDocument $fiscal, string $contents, string $fileName): array
            {
                throw new RuntimeException('storage is down');
            }
        };

        try {
            new RecordFiscalSubmissionAction($fiscal, artifacts: $failing)->execute();
            $this->fail('A send without its request artifact must not happen.');
        } catch (RuntimeException $e) {
            $this->assertSame('storage is down', $e->getMessage());
        }

        $this->assertCount(0, FakeEcfProvider::$submitted);
        $this->assertSame(0, $fiscal->submissions()->count());

        $attempt = new RecordFiscalSubmissionAction($fiscal->refresh(), artifacts: new FiscalArtifactService())->execute();

        $wire = (string) json_encode(['fake' => true, 'systemRef' => $attempt->system_ref, 'encf' => $fiscal->number]);
        $this->assertSame(hash('sha256', $wire), $attempt->request_sha256);
        $this->assertNotNull($attempt->request_file_id);
        $this->assertSame(hash('sha256', (string) json_encode(['fake_status' => 'accepted', 'systemRef' => $attempt->system_ref])), $attempt->response_sha256);
        $this->assertNotNull($attempt->response_file_id);
    }

    public function test_rolling_back_the_issue_leaves_no_fiscal_row(): void
    {
        $customer = $this->customer();
        $draft = $this->draftInvoice($customer);

        try {
            DB::connection('accounting')->transaction(function () use ($draft, $customer): void {
                new IssueInvoiceAction(invoice: $draft, billable: $customer, user: static::$cachedUser)->execute();

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, FiscalDocument::query()->forEntity($draft)->count());
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $draft->refresh()->document_status);
    }

    public function test_a_frozen_row_refuses_changes_rows_are_never_deleted_and_a_retained_file_stays(): void
    {
        $invoice = $this->accept($this->issued());
        $fiscal = EcfCompliance::current($invoice);
        $this->assertNotNull($fiscal->frozen_at);

        $fiscal->total = 1.0;

        try {
            $fiscal->save();
            $this->fail('A frozen money snapshot must not change.');
        } catch (ImmutableFiscalRecordException) {
        }

        try {
            $fiscal->refresh()->delete();
            $this->fail('A fiscal row is never deleted.');
        } catch (ImmutableFiscalRecordException) {
        }

        $invoice->addFile($this->createFilesystemRow(companiesId: $this->company->getId()), ApplyFiscalAnswerAction::PDF_FIELD_NAME);
        /** @var FilesystemEntities $attached */
        $attached = $invoice->getFileByName(ApplyFiscalAnswerAction::PDF_FIELD_NAME);

        try {
            $attached->softDelete();
            $this->fail('A retained fiscal file cannot be detached.');
        } catch (ImmutableFiscalRecordException) {
        }

        $this->assertFalse($attached->refresh()->trashed());
    }

    public function test_a_new_range_keeps_the_old_one_and_unused_numbers_span_both_minus_a_partial_annulment(): void
    {
        $old = $this->activeRange(EcfTypeEnum::CREDITO_FISCAL);
        $this->issued();
        $this->configureRange(
            EcfTypeEnum::CREDITO_FISCAL,
            2000,
            2010,
            '2029-12-31'
        );
        $new = $this->activeRange(EcfTypeEnum::CREDITO_FISCAL);
        $invoice = $this->issued();

        $this->assertSame(FiscalRangeStatusEnum::SUPERSEDED, $old->refresh()->status);
        $this->assertSame($new->getKey(), EcfCompliance::current($invoice)->fiscal_number_range_id);
        $this->assertSame('E310000002000', $invoice->invoice_number);

        $annulment = new RecordFiscalRangeAnnulmentAction(
            app: $this->kanvasApp,
            company: $this->company,
            environment: 'test',
            lines: [
                ['document_type' => '31', 'series' => 'E31', 'from' => 5, 'to' => 1000],
                ['document_type' => '31', 'series' => 'E31', 'from' => 2001, 'to' => 2005],
            ],
            provider: 'fake',
        )->execute();
        $lines = $annulment->lines()->orderBy('range_from')->get();
        RecordFiscalRangeAnnulmentAction::settle($annulment, [
            $lines[0]->getKey() => ['annulled' => true],
            $lines[1]->getKey() => ['annulled' => false, 'message' => 'Secuencia ya utilizada'],
        ]);

        $this->assertSame('partial', $annulment->refresh()->status);
        $this->assertSame(FiscalRangeAnnulmentLine::REFUSED, $lines[1]->refresh()->result);

        $unused = new FiscalNumberRangeService()->unusedForType(
            $this->company->getId(),
            'test',
            'DO',
            '31'
        );

        $this->assertSame([], $unused[$old->getKey()], 'E31 4 is used and 5–1000 annulled.');
        $this->assertSame([[2001, 2010]], $unused[$new->getKey()]);
    }

    public function test_a_credit_note_relates_to_its_parents_fiscal_row(): void
    {
        $invoice = $this->acceptedInvoice();
        $customer = $this->customer();

        $creditNote = new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Devolución',
                        quantity: 1,
                        unit_price_native: 100.0,
                        tax_rate: 18.0,
                        tax_amount_native: 18.0
                    ),
                ]),
                currency: 'DOP',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();

        /** @var FiscalDocumentRelation $relation */
        $relation = EcfCompliance::current($creditNote)->relations()->sole();
        $this->assertSame(EcfCompliance::current($invoice)->getKey(), $relation->related_fiscal_document_id);
        $this->assertSame($invoice->invoice_number, $relation->related_reference);
        $this->assertSame(FiscalDocumentRelation::MODIFIES, $relation->relation_type);
    }

    public function test_the_backfill_moves_a_test_block_once_and_the_preflight_passes_on_test_data(): void
    {
        $invoice = $this->draftInvoice($this->customer());
        $invoice->regional_compliance = ['do_ecf' => [
            'encf' => 'E310000000777',
            'type' => '31',
            'provider' => 'fake',
            'provider_ref' => 'fake-ref',
            'status' => 'accepted',
            'security_code' => 'OLD123',
            'submitted_at' => '2026-06-15T10:00:00-04:00',
            'export' => ['incoterm' => 'FOB'],
        ]];
        $invoice->saveQuietly();

        $scope = ['--company' => [$this->company->getId()]];
        $this->artisan('scribe:dr-fiscal-preflight', [...$scope, '--skip-queue-check' => true])->assertSuccessful();
        $this->artisan('scribe:dr-fiscal-backfill', $scope)->assertSuccessful();
        $this->artisan('scribe:dr-fiscal-backfill', $scope)->assertSuccessful();

        $fiscal = FiscalDocument::query()->forEntity($invoice)->sole();
        $this->assertSame(FiscalDocumentStatusEnum::ACCEPTED, $fiscal->status);
        $this->assertNull($fiscal->authority_number_consumed);
        $this->assertSame('fake-ref', $fiscal->latestSubmission->provider_ref);
        $this->assertSame(1, $fiscal->submissions()->count());
        $this->assertEquals(['type' => '31', 'export' => ['incoterm' => 'FOB']], EcfCompliance::rawBlock($invoice->refresh()));
        $this->assertSame('OLD123', EcfCompliance::read($invoice)['security_code']);
    }

    public function test_the_preflight_fails_on_production_e_cf_data(): void
    {
        $this->company->set('scribe-ecf-environment', 'production');

        try {
            $invoice = $this->draftInvoice($this->customer());
            $invoice->regional_compliance = ['do_ecf' => ['encf' => 'E310000000778', 'status' => 'waiting', 'provider_ref' => 'x']];
            $invoice->saveQuietly();

            $scope = ['--company' => [$this->company->getId()]];
            $this->artisan('scribe:dr-fiscal-preflight', [...$scope, '--skip-queue-check' => true])->assertFailed();
            $this->artisan('scribe:dr-fiscal-backfill', $scope)->assertSuccessful();
            $this->assertSame(0, FiscalDocument::query()->forEntity($invoice)->count());
        } finally {
            $this->company->del('scribe-ecf-environment');
        }
    }

    public function test_a_received_vendor_e_cf_has_a_received_row_and_its_commercial_response_is_one_event(): void
    {
        $bill = $this->receivedBill();
        $fiscal = $this->fiscalRowFor(
            $bill,
            'E310000000123',
            status: FiscalDocumentStatusEnum::ACCEPTED,
            direction: FiscalDirectionEnum::RECEIVED,
            issuerTaxId: '00112345678',
        );

        ReceivedEcfCompliance::recordCommercialResponse($bill, ['event' => 2]);
        ReceivedEcfCompliance::recordCommercialResponse($bill, ['accepted' => true]);

        $this->assertSame(1, $fiscal->events()->where('event_type', FiscalEventTypeEnum::COMMERCIAL_RESPONSE->value)->count());
        $this->assertSame(['event' => 2, 'accepted' => true], ReceivedEcfCompliance::commercialResponse($bill));
        $this->assertNull(EcfCompliance::current($bill), 'A received row is never the bill\'s issued e-CF.');
    }

    private function issued(): Invoice
    {
        $customer = $this->customer();

        return new IssueInvoiceAction(
            invoice: $this->draftInvoice($customer),
            billable: $customer,
            user: static::$cachedUser,
        )->execute();
    }

    private function activeRange(EcfTypeEnum $type): FiscalNumberRange
    {
        return FiscalNumberRange::query()
            ->where('companies_id', $this->company->getId())
            ->where('document_type', $type->value)
            ->where('status', FiscalRangeStatusEnum::ACTIVE->value)
            ->sole();
    }
}
