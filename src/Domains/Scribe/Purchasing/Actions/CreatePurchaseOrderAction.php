<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Purchasing\Actions\Concerns\PersistsPurchaseOrderLines;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

/**
 * Creates a DRAFT purchase order for the company, numbered from its own `purchase_order` sequence. Nothing posts
 * to the ledger: an order is a commitment to the vendor, not an expense.
 */
class CreatePurchaseOrderAction
{
    use PersistsPurchaseOrderLines;

    public const string ORDER_TYPE = 'standard';
    public const string NUMBER_PREFIX = 'PO-';

    public function __construct(
        protected readonly PurchaseOrderData $data,
        protected readonly DocumentNumberAllocatorService $allocator = new DocumentNumberAllocatorService(),
    ) {
    }

    public function execute(): PurchaseOrder
    {
        $this->assertLines($this->data);

        return DB::connection('accounting')->transaction(function (): PurchaseOrder {
            $order = new PurchaseOrder();
            $order->apps_id = $this->data->app->getId();
            $order->companies_id = $this->data->company->getId();
            $order->order_type = self::ORDER_TYPE;
            $order->order_number = $this->allocator->allocate(
                $order->apps_id,
                $order->companies_id,
                DocumentTypeEnum::PURCHASE_ORDER,
                self::NUMBER_PREFIX,
            );
            $order->vendor_organization_id = $this->data->vendor->getId();
            $order->document_status = PurchaseOrderStatusEnum::DRAFT;
            $order->order_date = $this->data->order_date ?? Carbon::today();
            $order->expected_date = $this->data->expected_date;
            $order->currency = $this->data->currency;
            $order->notes = $this->data->notes;
            $order->source = PurchaseOrder::SOURCE_KANVAS;
            $order->users_id = $this->data->user->getId();
            $order->order_total = 0;
            $order->saveOrFail();

            $this->persistLines($order, $this->data);

            return $order->fresh(['lines']);
        });
    }
}
