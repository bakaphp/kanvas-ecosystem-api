<?php

declare(strict_types=1);

namespace Tests\Scribe\Purchasing;

use Illuminate\Support\Facades\Mail;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\KanvasMailable;
use Kanvas\Scribe\Purchasing\Actions\SendPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\SubmitPurchaseOrderForApprovalAction;
use Kanvas\Scribe\Purchasing\Approvals\ApprovePurchaseOrderHandler;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderPdfService;
use Kanvas\Users\Models\Users;
use Tests\Scribe\Approvals\Concerns\SeedsBillApprovals;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestPurchaseOrder;

class PurchaseOrderApprovalAndSendTest extends ScribeTestCase
{
    use CreatesTestPurchaseOrder;
    use SeedsBillApprovals;

    public function test_a_company_with_no_policy_cannot_submit_an_order_and_it_stays_a_draft(): void
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('No Policy PO Vendor'));

        try {
            $this->submit($order);
            $this->fail('Without an approval policy nobody could answer the request.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no approval policy for purchase orders', $e->getMessage());
        }

        $this->assertSame(PurchaseOrderStatusEnum::DRAFT, $order->fresh()->document_status);
    }

    public function test_submitting_opens_a_request_for_the_vendors_approver(): void
    {
        [$order, $approver] = $this->submittedOrder();

        $request = $order->pendingApproval();

        $this->assertSame(PurchaseOrderStatusEnum::PENDING_APPROVAL, $order->document_status);
        $this->assertSame('approve_purchase_order', $request->approval_type);
        $this->assertSame([$approver->email], $request->pendingApproverEmails());
        $this->assertSame('USD', $request->payload['currency']);
    }

    public function test_the_vendors_approver_approves_the_order(): void
    {
        [$order, $approver] = $this->submittedOrder();

        $order->approve($approver);

        $approved = $order->fresh();
        $this->assertSame(PurchaseOrderStatusEnum::APPROVED, $approved->document_status);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_a_rejection_sends_the_order_back_to_draft_with_the_reason(): void
    {
        [$order, $approver] = $this->submittedOrder();

        $order->reject($approver, 'Wrong vendor');

        $rejected = $order->fresh();
        $this->assertSame(PurchaseOrderStatusEnum::DRAFT, $rejected->document_status);
        $this->assertStringContainsString('Rejected: Wrong vendor', $rejected->notes);
    }

    public function test_an_expired_approval_returns_the_order_to_draft(): void
    {
        [$order] = $this->submittedOrder();

        $order->approvalExpired($order->pendingApproval());

        $expired = $order->fresh();
        $this->assertSame(PurchaseOrderStatusEnum::DRAFT, $expired->document_status);
        $this->assertStringContainsString('expired with no decision', $expired->notes);
    }

    public function test_an_order_mirrored_from_an_erp_cannot_be_submitted(): void
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Mirrored Submit Vendor'));
        $order->document_status = null;
        $order->source = 'external-erp';
        $order->save();

        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('comes from an external system');

        $this->submit($order);
    }

    public function test_an_approved_order_is_emailed_to_the_vendor_as_a_pdf_and_marked_sent(): void
    {
        $this->skipWithoutPdfBinary();
        Mail::fake();
        $order = $this->approvedOrder();

        $sent = $this->send($order, ['purchasing@vendor.example.test', ' purchasing@vendor.example.test ']);

        Mail::assertSent(
            KanvasMailable::class,
            static fn (KanvasMailable $mail): bool => $mail->hasTo('purchasing@vendor.example.test')
                && count($mail->rawAttachments) === 1
                && str_starts_with($mail->rawAttachments[0]['name'], 'purchase-order-PO-')
        );
        $this->assertSame(PurchaseOrderStatusEnum::SENT, $sent->document_status);
        $this->assertSame('purchasing@vendor.example.test', $sent->sent_to);
        $this->assertNotNull($sent->sent_at);
        $this->assertNotNull($sent->getFileByName(PurchaseOrderPdfService::FIELD_NAME));
    }

    public function test_a_draft_order_cannot_be_sent(): void
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Draft Send Vendor'));

        $this->expectException(InvalidPurchaseOrderTransitionException::class);

        $this->send($order, ['purchasing@vendor.example.test']);
    }

    public function test_an_order_needs_a_valid_address_to_be_sent_to(): void
    {
        $this->skipWithoutPdfBinary();
        $order = $this->approvedOrder();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least one valid email');

        $this->send($order, ['not-an-email']);
    }

    public function test_a_sent_order_can_be_sent_again_to_another_contact(): void
    {
        $this->skipWithoutPdfBinary();
        Mail::fake();
        $order = $this->approvedOrder();

        $this->send($order, ['first@vendor.example.test']);
        $again = $this->send($order->fresh(), ['second@vendor.example.test']);

        Mail::assertSentCount(2);
        $this->assertSame('second@vendor.example.test', $again->sent_to);
        $this->assertSame(PurchaseOrderStatusEnum::SENT, $again->document_status);
    }

    /**
     * @return array{PurchaseOrder, Users}
     */
    private function submittedOrder(): array
    {
        $vendor = $this->seedTestOrganization('Approval PO Vendor');
        $approver = $this->linkApprover($vendor, 'po-approver');
        $this->seedPolicy(PurchaseOrder::class, 'approve_purchase_order', 'vendor', ApprovePurchaseOrderHandler::class);

        return [$this->submit($this->createPurchaseOrderFor($vendor)), $approver];
    }

    private function approvedOrder(): PurchaseOrder
    {
        [$order, $approver] = $this->submittedOrder();
        $order->approve($approver);

        return $order->fresh();
    }

    private function submit(PurchaseOrder $order): PurchaseOrder
    {
        return new SubmitPurchaseOrderForApprovalAction($order, static::$cachedUser)->execute();
    }

    /**
     * @param list<string> $recipients
     */
    private function send(PurchaseOrder $order, array $recipients): PurchaseOrder
    {
        return new SendPurchaseOrderAction(
            order: $order,
            user: static::$cachedUser,
            recipients: $recipients,
            message: 'Please confirm the delivery date.',
            fetchPdf: static fn (string $url): string => '%PDF-test',
        )->execute();
    }
}
