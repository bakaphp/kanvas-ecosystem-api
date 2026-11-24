<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\CheckApprovalStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Approvals\RetryApprovedItemTool;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * A failed e-CF action retains its approval and retries the original invoice instead of issuing a duplicate.
 */
class ApprovedActionRetryTest extends EcfTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->artisan('kanvas-scribe:seed-ecf-approval-policy', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();
    }

    public function test_a_failed_approved_action_is_reported_as_failed_and_runs_again_once_fixed(): void
    {
        $customer = $this->customer();
        $invoice = ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Invoice => new SubmitInvoiceForApprovalAction($this->draftInvoice($customer), static::$cachedUser)->execute()
        );
        $request = $invoice->pendingApproval();
        $this->assertNotNull($request);

        // The range disappears between submit and approval: the approval stands, the e-CF step fails.
        DocumentSequence::query()->where('companies_id', $this->company->getId())->where('document_type', 'ecf_31')->delete();
        new ApproveAction($request, static::$cachedUser)->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->refresh()->document_status);
        $this->assertStringContainsString('E31', (string) $request->refresh()->handlerError());

        $status = $this->tool(CheckApprovalStatusTool::class)->__invoke(target_type: 'invoice', target_id: $invoice->getId());
        $this->assertTrue($status['approved']);
        $this->assertFalse($status['action_completed']);
        $this->assertStringContainsString('FAILED and did not happen', $status['message']);
        $this->assertStringContainsString('retry_approved_item', $status['message']);

        $stillBroken = $this->tool(RetryApprovedItemTool::class)->__invoke(target_type: 'invoice', target_id: $invoice->getId());
        $this->assertFalse($stillBroken['success']);
        $this->assertStringContainsString('failed again', $stillBroken['error']);

        $this->configureRange(
            EcfTypeEnum::CREDITO_FISCAL,
            4,
            1000,
            '2028-12-31'
        );
        $retried = $this->tool(RetryApprovedItemTool::class)->__invoke(target_type: 'invoice', target_id: $invoice->getId());

        $this->assertTrue($retried['success']);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->refresh()->document_status);
        $this->assertStringStartsWith('E31', (string) $invoice->invoice_number);
        $this->assertNull($request->refresh()->handlerError());
        $this->assertTrue($this->tool(CheckApprovalStatusTool::class)->__invoke(target_type: 'invoice', target_id: $invoice->getId())['action_completed']);
    }

    public function test_a_clean_approval_has_nothing_to_retry(): void
    {
        $customer = $this->customer();
        $invoice = ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Invoice => new SubmitInvoiceForApprovalAction($this->draftInvoice($customer), static::$cachedUser)->execute()
        );
        new ApproveAction($invoice->pendingApproval(), static::$cachedUser)->execute();

        $result = $this->tool(RetryApprovedItemTool::class)->__invoke(target_type: 'invoice', target_id: $invoice->getId());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nothing to retry', $result['error']);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function tool(string $class): object
    {
        return new $class()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
