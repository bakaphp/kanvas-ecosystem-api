<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;

/**
 * Records that the documents package and the due-date notice went to the customer.
 */
class MarkDocumentsPackageSentAction
{
    public function __construct(
        protected readonly SalesOrder $order,
    ) {
    }

    public function execute(): SalesOrderShipment
    {
        $shipment = $this->order->shipment;

        if ($shipment === null || $this->order->document_status !== SalesOrderStatusEnum::SHIPPED) {
            throw new ValidationException(
                "Sales order {$this->order->order_number} has not shipped, so there is no documents package to send."
            );
        }

        if ($shipment->documents_package_sent_at === null) {
            $shipment->documents_package_sent_at = Carbon::now();
            $shipment->saveOrFail();
        }

        return $shipment;
    }
}
