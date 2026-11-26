<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;
use Kanvas\Users\Models\Users;

/**
 * Records the bill of lading of an accepted order: the shipping documents must be cross-checked with every difference
 * clarified. The invoice falls due the order's payment term after the bill of lading date, and the documents package
 * is due within 3 business days of it and at least 5 days before the arrival, whichever comes first.
 */
class RecordShipmentAction
{
    public const int PACKAGE_BUSINESS_DAYS = 3;
    public const int PACKAGE_DAYS_BEFORE_ARRIVAL = 5;

    /**
     * @param list<string> $containers
     */
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly string $blNumber,
        protected readonly Carbon $blDate,
        protected readonly Users $user,
        protected readonly ?string $vessel = null,
        protected readonly array $containers = [],
        protected readonly ?Carbon $eta = null,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrderShipment
    {
        $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::SHIPPED);
        $this->assertReadyToShip();

        return DB::connection('accounting')->transaction(function (): SalesOrderShipment {
            $dueDate = $this->blDate->copy()->addDays($this->order->payment_terms_days);

            new SetInvoiceDueFromBlAction(
                $this->order->invoice,
                $dueDate,
                $this->blNumber,
                $this->user
            )->execute();

            $this->order->document_status = SalesOrderStatusEnum::SHIPPED;
            $this->order->eta = $this->eta ?? $this->order->eta;
            $this->order->saveOrFail();

            return SalesOrderShipment::create([
                'apps_id' => $this->order->apps_id,
                'companies_id' => $this->order->companies_id,
                'sales_order_id' => $this->order->getId(),
                'bl_number' => $this->blNumber,
                'bl_date' => $this->blDate,
                'vessel' => $this->vessel,
                'containers' => $this->containers,
                'payment_terms_days' => $this->order->payment_terms_days,
                'due_date' => $dueDate,
                'documents_package_due_on' => $this->packageDueOn(),
                'recorded_at' => Carbon::now(),
                'users_id' => $this->user->getId(),
            ]);
        });
    }

    private function assertReadyToShip(): void
    {
        if ($this->order->documents_checked_at === null) {
            throw new ValidationException(
                "Cross-check the shipping documents of {$this->order->order_number} before the bill of lading is recorded."
            );
        }

        $open = $this->order->openDocumentsDifferenceKeys();

        if ($open !== []) {
            throw new ValidationException(
                'Clarify every difference in the shipping documents first. Still open: ' . implode(', ', $open) . '.'
            );
        }

        if ($this->order->payment_terms_days === null) {
            throw new ValidationException(
                "Sales order {$this->order->order_number} has no payment term: set it so the invoice has a due date."
            );
        }
    }

    private function packageDueOn(): Carbon
    {
        $byBusinessDays = $this->blDate->copy()->addWeekdays(self::PACKAGE_BUSINESS_DAYS);
        $eta = $this->eta ?? $this->order->eta;

        if ($eta === null) {
            return $byBusinessDays;
        }

        return $byBusinessDays
            ->min($eta->copy()->subDays(self::PACKAGE_DAYS_BEFORE_ARRIVAL))
            ->max($this->blDate);
    }
}
