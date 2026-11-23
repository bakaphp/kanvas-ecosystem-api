<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DiscardDraftInvoiceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\UpdateDraftInvoiceTool;
use Kanvas\Scribe\Approvals\Actions\ResolveApprovalAction;
use Kanvas\Scribe\Approvals\Actions\SeedScribeApprovalPoliciesAction;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Override;
use RuntimeException;
use Tests\Scribe\ElectronicInvoicing\EcfTestCase;

/**
 * Changing a draft must not take discard-and-create: when the replacement is refused, a SOW payment is
 * left with no invoice and no schedule to make another.
 */
class UpdateDraftInvoiceToolTest extends EcfTestCase
{
    protected function companyIssuesEcf(): bool
    {
        return false;
    }

    public function test_a_draft_gets_new_lines_and_its_approver_is_asked_again(): void
    {
        new SeedScribeApprovalPoliciesAction(
            app: $this->kanvasApp,
            company: $this->company,
            defaultApprover: static::$cachedUser,
        )->execute();
        $customer = $this->customer();
        $draft = $this->draftInvoice($customer, null);
        ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn () => new SubmitInvoiceForApprovalAction($draft, static::$cachedUser)->execute()
        );
        $firstRequest = $draft->refresh()->pendingApproval();
        $this->assertNotNull($firstRequest);

        $result = $this->updateTool()->__invoke(
            invoice_id: $draft->getId(),
            lines: [
                ['description' => 'Mes 1', 'unit_price' => 600.0, 'tax_rate' => 18],
                ['description' => 'Soporte', 'quantity' => 2, 'unit_price' => 100.0],
            ],
            memo: 'Primer pago',
        );

        $draft->refresh();
        $this->assertTrue($result['success']);
        $this->assertSame('requested_again', $result['approval']);
        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $draft->document_status);
        $this->assertSame(['Mes 1', 'Soporte'], $draft->lines()->orderBy('id')->pluck('description')->all());
        $this->assertEqualsWithDelta(908.0, (float) $draft->total_native, 0.001, '600 + 108 ITBIS + 2 x 100');
        $this->assertSame('Primer pago', $draft->notes);
        $this->assertSame($customer->getId(), (int) $draft->customer_organization_id, 'the header it did not touch survives');
        $this->assertSame('2026-07-15', $draft->due_date->toDateString());
        $this->assertSame(ApprovalStatusEnum::CANCELLED, $firstRequest->refresh()->status);
        $this->assertNotSame($firstRequest->getId(), $draft->pendingApproval()?->getId(), 'the approver sees the new lines');
        $this->assertSame($draft->pendingApproval()->getId(), $result['approval_request_id'], 'and the agent is told which one');
    }

    /**
     * A tenant with no approval policy waits on the legacy queue, which pendingApprovals() never sees: a
     * row left pending there still shows the old amount, and approving it issues the edited invoice.
     */
    public function test_a_tenant_on_the_legacy_queue_gets_its_old_request_closed_and_a_new_one_opened(): void
    {
        $customer = $this->customer();
        $draft = $this->draftInvoice($customer, null);
        new SubmitInvoiceForApprovalAction($draft, static::$cachedUser)->execute();
        $this->assertNull($draft->refresh()->pendingApproval(), 'no policy, so no generic request');
        /** @var ApprovalQueueItem $stale */
        $stale = $draft->pendingLegacyApprovals()->sole();

        $result = $this->updateTool()->__invoke(
            invoice_id: $draft->getId(),
            lines: [['description' => 'Una línea', 'unit_price' => 250.0]],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('requested_again', $result['approval']);
        $this->assertSame(ApprovalQueueStatusEnum::EXPIRED, $stale->refresh()->status);
        $this->assertSame('draft edited', $stale->reason);
        /** @var ApprovalQueueItem $current */
        $current = $draft->pendingLegacyApprovals()->sole();
        $this->assertNotSame($stale->getId(), $current->getId());
        $this->assertSame($current->getId(), $result['approval_request_id']);
        $this->assertTrue($result['approval_pending']);

        try {
            new ResolveApprovalAction($stale, static::$cachedUser)->execute();
            $this->fail('The request that showed the old amount must not issue the edited invoice.');
        } catch (ValidationException) {
            $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $draft->refresh()->document_status);
        }
    }

    /**
     * Saved before the withdrawal, an edit outlives its failure: the old request stays approvable and
     * issues lines its approver never saw. Nothing may change when the request cannot be closed.
     */
    public function test_an_approval_that_cannot_be_withdrawn_leaves_the_draft_untouched(): void
    {
        new SeedScribeApprovalPoliciesAction(
            app: $this->kanvasApp,
            company: $this->company,
            defaultApprover: static::$cachedUser,
        )->execute();
        $draft = $this->draftInvoice($this->customer(), null);
        ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn () => new SubmitInvoiceForApprovalAction($draft, static::$cachedUser)->execute()
        );
        $request = $draft->refresh()->pendingApproval();
        $tool = new class () extends UpdateDraftInvoiceTool {
            #[Override]
            protected function withdrawApprovals(Invoice $invoice): int
            {
                throw new RuntimeException('approvals are unreachable');
            }
        };

        $result = $tool
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                invoice_id: $draft->getId(),
                lines: [['description' => 'Otra', 'unit_price' => 250.0]],
            );

        $draft->refresh();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('could not be withdrawn, so nothing was changed', $result['error']);
        $this->assertEqualsWithDelta(1180.0, (float) $draft->total_native, 0.001);
        $this->assertSame(['Servicio de consultoría'], $draft->lines()->pluck('description')->all());
        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);
        $this->assertTrue($result['approval_pending'], 'and the reply says what is really waiting');
        $this->assertSame($request->getId(), $result['approval_request_id']);
    }

    public function test_a_draft_nobody_was_asked_about_is_edited_without_opening_an_approval(): void
    {
        $draft = $this->draftInvoice($this->customer(), null);

        $result = $this->updateTool()->__invoke(
            invoice_id: $draft->getId(),
            lines: [['description' => 'Una línea', 'unit_price' => 250.0]],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('none_pending', $result['approval']);
        $this->assertNull($draft->refresh()->pendingApproval());
        $this->assertEqualsWithDelta(250.0, (float) $draft->total_native, 0.001);
    }

    public function test_a_scheduled_payment_can_be_reworded_but_keeps_its_total(): void
    {
        $draft = $this->scheduledDraft($this->customer());
        $this->assertEqualsWithDelta(11800.0, (float) $draft->total_native, 0.01);

        $refused = $this->updateTool()->__invoke(
            invoice_id: $draft->getId(),
            lines: [['description' => 'Mes 1', 'unit_price' => 9000.0, 'tax_rate' => 18]],
        );
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('keeps its total', $refused['error']);
        $this->assertSame(3, $draft->refresh()->lines()->count(), 'a refused edit changes nothing');

        $merged = $this->updateTool()->__invoke(
            invoice_id: $draft->getId(),
            lines: [['description' => 'Mes 1', 'unit_price' => 10000.0, 'tax_rate' => 18]],
        );
        $this->assertTrue($merged['success']);
        $this->assertSame(['Mes 1'], $draft->refresh()->lines()->pluck('description')->all());
        $this->assertEqualsWithDelta(11800.0, (float) $draft->total_native, 0.001);
        $this->assertSame(922, $draft->metadata['billing_schedule_id'], 'still that schedule\'s invoice');
    }

    public function test_an_issued_invoice_and_bad_lines_are_refused(): void
    {
        $customer = $this->customer();
        $issued = new IssueInvoiceAction($this->draftInvoice($customer, null), $customer, static::$cachedUser)->execute();

        $result = $this->updateTool()->__invoke(
            invoice_id: $issued->getId(),
            lines: [['description' => 'Otro', 'unit_price' => 5.0]],
        );
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a draft', $result['error']);

        $draft = $this->draftInvoice($customer, null);
        $bad = $this->updateTool()->__invoke(
            invoice_id: $draft->getId(),
            lines: [['description' => 'Gratis', 'unit_price' => 0]],
        );
        $this->assertSame('invalid_args', $bad['outcome']);
        $this->assertEqualsWithDelta(1180.0, (float) $draft->refresh()->total_native, 0.001);
    }

    public function test_a_scheduled_payment_is_not_discarded_unless_it_is_meant_to_stay_unbilled(): void
    {
        $draft = $this->scheduledDraft($this->customer());
        $discard = new DiscardDraftInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $refused = $discard->__invoke(invoice_id: $draft->getId(), reason: 'replace it');
        $this->assertFalse($refused['success']);
        $this->assertSame('scheduled_payment', $refused['reason']);
        $this->assertStringContainsString('update_draft_invoice', $refused['note']);
        $this->assertNotNull(Invoice::query()->whereKey($draft->getId())->notDeleted()->first());

        $discarded = $discard->__invoke(
            invoice_id: $draft->getId(),
            reason: 'the customer cancelled this payment',
            leave_payment_unbilled: true,
        );
        $this->assertTrue($discarded['success']);
        $this->assertStringContainsString('now unbilled', $discarded['note']);
        $this->assertNull(Invoice::query()->whereKey($draft->getId())->notDeleted()->first());
    }

    /** A draft as a billing schedule leaves it: three quote lines at a third each, tagged with the schedule. */
    private function scheduledDraft(Organization $customer): Invoice
    {
        $draft = $this->draftInvoice(
            $customer,
            null,
            [
                [3333.33, 600.0],
                [3333.33, 600.0],
                [3333.34, 600.0],
            ],
        );
        $draft->metadata = [
            'billing_schedule_id' => 922,
            'billing_period' => '2026-06-15',
        ];
        $draft->save();

        return $draft->refresh();
    }

    private function updateTool(): UpdateDraftInvoiceTool
    {
        return new UpdateDraftInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
