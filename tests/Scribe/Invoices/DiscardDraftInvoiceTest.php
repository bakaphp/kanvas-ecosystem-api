<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\DiscardDraftInvoiceTool;
use Kanvas\Scribe\Approvals\Actions\SeedScribeApprovalPoliciesAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Tests\Scribe\ElectronicInvoicing\EcfTestCase;

/**
 * Two SOW draft invoices had the wrong lines and nothing could remove them: void refuses a draft (nothing posted to
 * reverse) and no tool deleted one, so the agent was stuck.
 */
class DiscardDraftInvoiceTest extends EcfTestCase
{
    protected function companyIssuesEcf(): bool
    {
        return false;
    }

    public function test_a_draft_is_withdrawn_with_its_pending_approval(): void
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
        $request = $draft->refresh()->pendingApproval();
        $this->assertNotNull($request, 'an agent draft waits for a person');

        $result = $this->tool()->__invoke(invoice_id: $draft->getId(), reason: 'wrong lines');

        $this->assertTrue($result['success']);
        $this->assertNull(Invoice::query()->whereKey($draft->getId())->notDeleted()->first(), 'gone from every list');
        $this->assertSame(ApprovalStatusEnum::CANCELLED, $request->refresh()->status);
        $this->assertSame('not_found', $this->tool()->__invoke(invoice_id: $draft->getId())['outcome'], 'discarding it again finds nothing');
    }

    public function test_an_issued_invoice_is_refused_and_pointed_at_void(): void
    {
        $customer = $this->customer();
        $issued = new IssueInvoiceAction($this->draftInvoice($customer, null), $customer, static::$cachedUser)->execute();

        $result = $this->tool()->__invoke(invoice_id: $issued->getId());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('void it instead', $result['error']);
        $this->assertNotNull(Invoice::query()->whereKey($issued->getId())->notDeleted()->first());
    }

    private function tool(): DiscardDraftInvoiceTool
    {
        return new DiscardDraftInvoiceTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
