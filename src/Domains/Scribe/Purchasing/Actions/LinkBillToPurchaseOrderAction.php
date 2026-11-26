<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderMatchService;

/**
 * Links a vendor bill to the purchase order it bills, once the three-way match finds nothing to explain. A bill that
 * does not match is refused with the reasons: the bill is corrected or the receipt recorded, then it is linked.
 */
class LinkBillToPurchaseOrderAction
{
    public function __construct(
        protected readonly Bill $bill,
        protected readonly PurchaseOrder $order,
        protected readonly PurchaseOrderMatchService $matcher = new PurchaseOrderMatchService(),
    ) {
    }

    public function execute(): Bill
    {
        $sameTenant = (int) $this->bill->apps_id === (int) $this->order->apps_id
            && (int) $this->bill->companies_id === (int) $this->order->companies_id;

        if (! $sameTenant) {
            throw new ValidationException('The bill and the purchase order belong to different companies.');
        }

        if ($this->bill->document_status === BillDocumentStatusEnum::VOIDED) {
            throw new ValidationException("Bill {$this->bill->bill_number} is voided.");
        }

        $match = $this->matcher->match($this->order, $this->bill);

        if (! $match->matched()) {
            throw new ValidationException(
                "Bill {$this->bill->bill_number} does not match purchase order {$this->order->order_number}: "
                . implode(', ', $match->keys()) . '.'
            );
        }

        $this->bill->purchase_order_id = $this->order->getId();
        $this->bill->saveOrFail();

        return $this->bill;
    }
}
