<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;

/**
 * Records the delivery against the signed proof of delivery, with any discrepancy or damage noted on it.
 */
class ConfirmDeliveryAction
{
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
        $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::DELIVERED);

        $pod = Str::trimToNull($this->podReference)
            ?? throw new ValidationException('A delivery needs the reference of the signed proof of delivery.');

        return DB::connection('accounting')->transaction(function () use ($pod): SalesOrderShipment {
            $shipment = $this->order->shipment;
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
