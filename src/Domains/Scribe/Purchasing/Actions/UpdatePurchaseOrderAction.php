<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
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
    use LocksDocumentRow;
    use PersistsPurchaseOrderLines;

    public function __construct(
        protected readonly PurchaseOrder $order,
        protected readonly PurchaseOrderData $data,
    ) {
    }

    public function execute(): PurchaseOrder
    {
        $this->assertLines($this->data);

        return $this->withRowLocked($this->order, function (): PurchaseOrder {
            $order = $this->order;

            if (! $order->isIssuedByCompany()) {
                throw InvalidPurchaseOrderTransitionException::notIssuedByCompany($order, 'edited');
            }

            if ($order->document_status !== PurchaseOrderStatusEnum::DRAFT) {
                throw new InvalidPurchaseOrderTransitionException(
                    "Purchase order {$order->order_number} is {$order->document_status->value}: "
                    . 'only a draft can be edited.'
                );
            }

            $order->forceFill($this->data->sent([
                'currency' => $this->data->currency,
                'order_date' => $this->data->order_date,
                'expected_date' => $this->data->expected_date,
                'notes' => $this->data->notes,
            ]));

            if ($this->data->changes('vendor_id')) {
                $order->vendor_organization_id = $this->data->vendor->getId();
            }

            if ($this->data->changes('lines')) {
                $this->persistLines($order, $this->data);
            } else {
                $order->saveOrFail();
            }

            return $order->fresh(['lines']);
        });
    }
}
