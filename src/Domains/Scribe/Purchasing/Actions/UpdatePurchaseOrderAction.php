<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Purchasing\Actions\Concerns\PersistsPurchaseOrderLines;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

/**
 * Edits a draft purchase order the company issued. The lines are replaced as a whole.
 */
class UpdatePurchaseOrderAction
{
    use PersistsPurchaseOrderLines;

    public function __construct(
        protected readonly PurchaseOrder $order,
        protected readonly PurchaseOrderData $data,
    ) {
    }

    public function execute(): PurchaseOrder
    {
        if (! $this->order->isIssuedByCompany()) {
            throw InvalidPurchaseOrderTransitionException::notIssuedByCompany($this->order, 'edited');
        }

        if ($this->order->document_status !== PurchaseOrderStatusEnum::DRAFT) {
            throw new InvalidPurchaseOrderTransitionException(
                "Purchase order {$this->order->order_number} is {$this->order->document_status->value}: only a draft can be edited."
            );
        }

        $this->assertLines($this->data);

        return DB::connection('accounting')->transaction(function (): PurchaseOrder {
            $this->order->vendor_organization_id = $this->data->vendor->getId();
            $this->order->currency = $this->data->currency;
            $this->order->order_date = $this->data->order_date;
            $this->order->expected_date = $this->data->expected_date;
            $this->order->notes = $this->data->notes;

            $this->persistLines($this->order, $this->data);

            return $this->order->fresh(['lines']);
        });
    }
}
