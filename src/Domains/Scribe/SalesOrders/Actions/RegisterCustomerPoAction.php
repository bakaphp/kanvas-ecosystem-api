<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\SalesOrders\Actions\Concerns\PersistsSalesOrderLines;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use Kanvas\Scribe\SalesOrders\Enums\PoSourceEnum;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;

/**
 * Registers what a customer committed to buy, as a RECEIVED sales order with its own number. Nothing is promised to
 * the customer yet: the order still has to be reviewed against its quote and confirmed.
 */
class RegisterCustomerPoAction
{
    use PersistsSalesOrderLines;

    public const string NUMBER_PREFIX = 'SO-';

    public function __construct(
        protected readonly SalesOrderData $data,
        protected readonly DocumentNumberAllocatorService $allocator = new DocumentNumberAllocatorService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        $this->assertRegistrable();
        $this->assertLines($this->data);

        return DB::connection('accounting')->transaction(function (): SalesOrder {
            $order = new SalesOrder();
            $order->apps_id = $this->data->app->getId();
            $order->companies_id = $this->data->company->getId();
            $order->order_number = $this->allocator->allocate(
                $order->apps_id,
                $order->companies_id,
                DocumentTypeEnum::SALES_ORDER,
                self::NUMBER_PREFIX,
            );
            $order->document_status = SalesOrderStatusEnum::RECEIVED;
            $order->po_source = $this->data->po_source;
            $order->customer_organization_id = $this->data->customer->getId();
            $order->quote_id = $this->data->quote?->getId();
            $order->customer_po_number = $this->data->customer_po_number;
            $order->customer_po_date = $this->data->customer_po_date;
            $order->signature_checklist = $this->data->signature_checklist;
            $order->currency = $this->data->currency;
            $order->incoterm = $this->data->incoterm;
            $order->consignee = $this->data->consignee;
            $order->payment_terms_days = $this->data->payment_terms_days;
            $order->requested_date = $this->data->requested_date;
            $order->notes = $this->data->notes;
            $order->received_at = Carbon::now();
            $order->users_id = $this->data->user->getId();
            $order->order_total = 0;
            $order->saveOrFail();

            $this->persistLines($order, $this->data);

            return $order->fresh(['lines']);
        });
    }

    private function assertRegistrable(): void
    {
        if ($this->data->customer_po_number === null) {
            throw new ValidationException('A sales order needs the number of the customer\'s purchase order or signed proforma.');
        }

        if ($this->data->po_source === PoSourceEnum::CUSTOMER_PO && $this->data->customer_po_date === null) {
            throw new ValidationException('A customer\'s purchase order needs its date.');
        }

        if ($this->data->po_source === PoSourceEnum::SIGNED_PROFORMA && $this->data->quote === null) {
            throw new ValidationException('A signed proforma is one of our quotes: say which quote it is.');
        }

        $existing = SalesOrder::query()
            ->fromApp($this->data->app)
            ->fromCompany($this->data->company)
            ->notDeleted()
            ->where('customer_organization_id', $this->data->customer->getId())
            ->where('customer_po_number', $this->data->customer_po_number)
            ->first();

        if ($existing !== null) {
            throw new ValidationException(
                "{$this->data->customer_po_number} is already registered for this customer as {$existing->order_number}."
            );
        }
    }
}
