<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Contracts\ApprovalRejectionHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Purchasing\Actions\ApprovePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\RejectPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Override;

/**
 * Approving a purchase order only moves it to approved: sending it to the vendor is its own step, so unlike the bill
 * handler there is nothing to push. Rejecting sends it back to draft.
 */
class ApprovePurchaseOrderHandler implements ApprovalHandlerInterface, ApprovalRejectionHandlerInterface
{
    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        $order = $this->order($request);
        $order = new ApprovePurchaseOrderAction($order)->execute();

        return $this->result($order);
    }

    #[Override]
    public function reject(ApprovalRequest $request, ?UserInterface $approver, ?string $reason): array
    {
        $order = new RejectPurchaseOrderAction($this->order($request), $reason)->execute();

        return $this->result($order);
    }

    private function order(ApprovalRequest $request): PurchaseOrder
    {
        /** @var PurchaseOrder|null $order */
        $order = $request->resolveEntity();

        return $order ?? throw new ValidationException("Purchase order {$request->entity_id} no longer exists.");
    }

    /**
     * @return array<string, mixed>
     */
    private function result(PurchaseOrder $order): array
    {
        return [
            'target_type' => 'purchase_order',
            'target_id' => $order->getId(),
            'label' => $order->order_number,
            'pushed' => false,
            'reference' => null,
            'push_error' => null,
        ];
    }
}
