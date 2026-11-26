<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;

/**
 * Records the delivery against the signed proof of delivery, with any discrepancy or damage noted on it.
 */
class ConfirmDeliveryAction
{
    use LocksDocumentRow;

    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly Carbon $deliveredOn,
        protected readonly string $podReference,
        protected readonly ?string $discrepancies = null,
        protected readonly bool $damageReported = false,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrderShipment
    {
        $pod = Str::trimToNull($this->podReference)
            ?? throw new ValidationException('A delivery needs the reference of the signed proof of delivery.');

        return $this->withRowLocked($this->order, function () use ($pod): SalesOrderShipment {
            $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::DELIVERED);

            $shipment = $this->order->shipment;

            if ($this->deliveredOn->copy()->startOfDay()->lt($shipment->bl_date)) {
                throw new ValidationException(
                    "The delivery cannot be before the bill of lading date ({$shipment->bl_date->toDateString()})."
                );
            }

            $shipment->delivered_on = $this->deliveredOn;
            $shipment->pod_reference = $pod;
            $shipment->delivery_discrepancies = Str::trimToNull($this->discrepancies);
            $shipment->damage_reported = $this->damageReported;
            $shipment->delivery_recorded_at = Carbon::now();
            $shipment->saveOrFail();

            $this->order->document_status = SalesOrderStatusEnum::DELIVERED;
            $this->order->saveOrFail();

            return $shipment;
        });
    }
}
