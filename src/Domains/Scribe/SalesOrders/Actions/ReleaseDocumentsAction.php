<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;

/**
 * Gate 3: Operations releases the original bill of lading, or the telex release, only to a customer inside their
 * credit limit and without overdue invoices. Releasing twice changes nothing.
 */
class ReleaseDocumentsAction
{
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly CustomerCreditService $credit = new CustomerCreditService(),
    ) {
    }

    public function execute(): SalesOrderShipment
    {
        $shipment = $this->order->shipment;

        if ($shipment === null || $this->order->document_status !== SalesOrderStatusEnum::SHIPPED) {
            throw new ValidationException(
                "Sales order {$this->order->order_number} has not shipped, so there are no documents to release."
            );
        }

        if ($shipment->documents_released_at !== null) {
            return $shipment;
        }

        $this->credit->assertCanRelease($this->order);

        $shipment->documents_released_at = Carbon::now();
        $shipment->saveOrFail();

        return $shipment;
    }
}
