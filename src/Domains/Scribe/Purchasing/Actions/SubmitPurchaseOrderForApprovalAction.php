<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderStateMachineService;

/**
 * Moves a draft purchase order to PENDING_APPROVAL and opens its approval request with the vendor's approvers. A
 * company with no approval policy for purchase orders cannot submit one: nothing would ever answer the request.
 */
class SubmitPurchaseOrderForApprovalAction
{
    public const string TARGET_TYPE = 'purchase_order';

    public function __construct(
        protected readonly PurchaseOrder $order,
        protected readonly ?UserInterface $user = null,
        protected readonly PurchaseOrderStateMachineService $stateMachine = new PurchaseOrderStateMachineService(),
    ) {
    }

    public function execute(): PurchaseOrder
    {
        if (! $this->order->isIssuedByCompany()) {
            throw InvalidPurchaseOrderTransitionException::notIssuedByCompany($this->order, 'submitted for approval');
        }

        $this->stateMachine->assertTransition($this->order, PurchaseOrderStatusEnum::PENDING_APPROVAL);

        if ($this->order->document_status === PurchaseOrderStatusEnum::PENDING_APPROVAL) {
            return $this->order;
        }

        return DB::connection('accounting')->transaction(function (): PurchaseOrder {
            $this->order->document_status = PurchaseOrderStatusEnum::PENDING_APPROVAL;
            $this->order->saveOrFail();

            $request = $this->order->requestApproval(
                ApprovalRequest::approvalTypeFor(self::TARGET_TYPE),
                payload: [
                    'total_native' => (float) $this->order->order_total,
                    'currency' => $this->order->currency,
                    'vendor_organization_id' => $this->order->vendor_organization_id,
                ],
                requestedBy: $this->user,
            );

            if ($request === null) {
                throw new ValidationException(
                    'This company has no approval policy for purchase orders, so there is nobody to approve it. '
                    . 'Seed the approval policies first.'
                );
            }

            return $this->order->refresh();
        });
    }
}
