<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\RejectAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Connectors\Acumatica\Approvals\IssueAndPushInvoiceHandler;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateArInvoiceTool;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\Approvals\InvoiceApprovalPayload;
use Kanvas\Scribe\Invoices\Approvals\IssueInvoiceHandler;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;

class EcfApprovalPolicyTest extends EcfTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->artisan('kanvas-scribe:seed-ecf-approval-policy', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();
    }

    public function test_the_seed_creates_agent_only_policies_for_invoices_and_credit_notes(): void
    {
        foreach (['approve_invoice', 'approve_credit_note'] as $type) {
            $policy = $this->policy($type);

            $this->assertSame(IssueInvoiceHandler::class, $policy->handler);
            $this->assertSame('digest', $policy->notify);
            $this->assertEquals(
                ['field' => 'origin', 'operator' => '==', 'value' => 'agent'],
                $policy->approvalSteps()[0]->when
            );
        }
    }

    public function test_an_agent_invoice_waits_for_a_person_with_no_number_then_issues_on_approval(): void
    {
        $customer = $this->customer();
        $invoice = $this->submitAsAgent($this->draftInvoice($customer));

        $request = $invoice->pendingApproval();
        $this->assertNotNull($request);
        $this->assertSame(ApprovalOriginEnum::AGENT, $request->origin);
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status);
        $this->assertNull($invoice->invoice_number, 'No e-NCF is spent before approval.');
        $this->assertSame(InvoiceApprovalPayload::currentHash($invoice), $request->payload[InvoiceApprovalPayload::HASH_KEY]);
        $this->assertSame('31', $request->payload['ecf_type']);

        $result = new ApproveAction($request, static::$cachedUser)->execute();

        $this->assertArrayNotHasKey('handler_error', $result->handlerResult ?? []);
        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame('E310000000004', $invoice->invoice_number);
        $this->assertSame('E310000000004', $result->handlerResult['encf']);
        Queue::assertPushed(SubmitEcfJob::class);
    }

    public function test_a_rejected_approval_leaves_the_draft_without_a_number(): void
    {
        $invoice = $this->submitAsAgent($this->draftInvoice($this->customer()));

        new RejectAction($invoice->pendingApproval(), static::$cachedUser, 'Wrong customer')->execute();

        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status);
        $this->assertNull($invoice->invoice_number);
        $this->assertFalse(EcfCompliance::hasNumber($invoice));
    }

    public function test_the_handler_refuses_a_draft_changed_after_submission(): void
    {
        $invoice = $this->submitAsAgent($this->draftInvoice($this->customer()));
        $request = $invoice->pendingApproval();

        $invoice->total_native = 99999.0;
        $invoice->save();

        $result = new ApproveAction($request, static::$cachedUser)->execute();

        $this->assertStringContainsString('changed after it was submitted', (string) ($result->handlerResult['handler_error'] ?? ''));
        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status);
        $this->assertNull($invoice->invoice_number);
    }

    public function test_a_person_is_never_gated(): void
    {
        $customer = $this->customer();

        $issued = new IssueInvoiceAction($this->draftInvoice($customer), $customer, static::$cachedUser)->execute();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $issued->document_status);

        // A person who submits anyway has no applicable step: the request is closed by rule and issued.
        $submitted = new SubmitInvoiceForApprovalAction($this->draftInvoice($customer), static::$cachedUser)->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $submitted->document_status);
        $this->assertSame(ApprovalStatusEnum::APPROVED, $submitted->approvalRequests()->first()->status);
    }

    public function test_turning_invoice_approval_off_never_turns_off_credit_notes(): void
    {
        $policy = $this->policy('approve_invoice');
        $steps = $policy->steps;
        $steps[0]['when'] = ['field' => 'origin', 'operator' => '==', 'value' => 'nobody'];
        $policy->steps = $steps;
        $policy->saveOrFail();

        $customer = $this->customer();
        $invoice = $this->submitAsAgent($this->draftInvoice($customer));
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status, 'Off means the agent invoice issues.');
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($invoice))->handle();

        $creditNote = ApprovalOriginService::during(ApprovalOriginEnum::AGENT, function () use ($invoice, $customer): Invoice {
            $draft = new IssueCreditNoteAction(
                parentInvoice: $invoice,
                data: $this->creditNoteData($customer),
                user: static::$cachedUser,
            )->draft();

            return new SubmitInvoiceForApprovalAction($draft, static::$cachedUser)->execute();
        });

        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $creditNote->document_status);
        $this->assertSame('approve_credit_note', $creditNote->pendingApproval()?->approval_type);

        new ApproveAction($creditNote->pendingApproval(), static::$cachedUser)->execute();

        $this->assertSame('E340000000004', $creditNote->refresh()->invoice_number);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $creditNote->document_status);
    }

    public function test_the_agent_tool_submits_for_approval_by_default(): void
    {
        $customer = $this->customer('Herramienta Cliente SRL');
        $customer->tax_id = self::RNC;
        $customer->saveOrFail();

        $tool = new CreateArInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
        $result = $tool->__invoke(
            customer_name: 'Herramienta Cliente',
            memo: 'Consultoría',
            lines: [['description' => 'Consultoría', 'quantity' => 2, 'unit_price' => 500.0, 'tax_rate' => 18]],
            currency: 'DOP'
        );

        $this->assertTrue($result['created']);
        $this->assertTrue($result['awaiting_approval']);
        $invoice = Invoice::query()->findOrFail($result['invoice_id']);
        $this->assertSame(180.0, (float) $invoice->tax_native, 'the ITBIS the line states reaches the invoice');
        $this->assertSame(1180.0, (float) $invoice->total_native);
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status);
        $this->assertSame(ApprovalOriginEnum::AGENT, $invoice->pendingApproval()?->origin);
    }

    public function test_the_agent_tool_asked_to_issue_now_still_goes_to_approval(): void
    {
        $this->customer('Urgente Cliente SRL');

        $tool = new CreateArInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
        $result = $tool->__invoke(
            customer_name: 'Urgente Cliente',
            memo: 'Urgente',
            lines: [['description' => 'Urgente', 'unit_price' => 500.0, 'tax_rate' => 0]],
            currency: 'DOP',
            issue_immediately: true,
        );

        $this->assertTrue($result['awaiting_approval']);
        $this->assertStringContainsString("person's approval", (string) $result['note']);
    }

    public function test_the_agent_tool_refuses_an_e_cf_invoice_whose_tax_is_not_stated(): void
    {
        $this->customer('Sin Impuesto SRL');
        $tool = new CreateArInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $bare = $tool->__invoke(
            customer_name: 'Sin Impuesto',
            memo: 'Licencias',
            amount: 17700.0,
            currency: 'DOP'
        );
        $noRate = $tool->__invoke(
            customer_name: 'Sin Impuesto',
            memo: 'Licencias',
            lines: [['description' => 'Licencias', 'quantity' => 5, 'unit_price' => 3000.0]],
            currency: 'DOP',
        );

        $this->assertFalse($bare['created']);
        $this->assertStringContainsString('would reach DGII as exempt', $bare['message']);
        $this->assertFalse($noRate['created']);
        $this->assertStringContainsString('every line needs tax_rate', $noRate['message']);
    }

    public function test_stored_acumatica_policy_rows_keep_resolving_to_the_same_handler(): void
    {
        $this->assertTrue(is_a(IssueAndPushInvoiceHandler::class, IssueInvoiceHandler::class, true));
    }

    private function submitAsAgent(Invoice $draft): Invoice
    {
        return ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Invoice => new SubmitInvoiceForApprovalAction($draft, static::$cachedUser)->execute()
        );
    }

    private function policy(string $approvalType): ApprovalPolicy
    {
        return ApprovalPolicy::query()
            ->where('companies_id', $this->company->getId())
            ->where('approval_type', $approvalType)
            ->notDeleted()
            ->firstOrFail();
    }

    private function creditNoteData(Organization $customer): InvoiceData
    {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: $customer,
            lines: new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(
                    description: 'Nota de crédito',
                    quantity: 1,
                    unit_price_native: 1000.0,
                    tax_rate: 18.0,
                    tax_amount_native: 180.0,
                ),
            ]),
            currency: 'DOP',
            fx_rate_to_base: 1.0,
        );
    }
}
