<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;
use Kanvas\Scribe\SalesOrders\Services\ShippingDocumentsCheckService;

/**
 * Cross-checks the order, the commercial invoice, the packing list and the draft bill of lading before the bill of
 * lading is issued, and records every difference. The invoice chosen here is the one that falls due from the bill of
 * lading date.
 */
class CheckShippingDocumentsAction
{
    use LocksDocumentRow;

    /**
     * @param list<array{quantity: float|int}> $packingList
     * @param array{total_quantity: float|int} $blDraft
     */
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly Invoice $invoice,
        protected readonly array $packingList,
        protected readonly array $blDraft,
        protected readonly ShippingDocumentsCheckService $check = new ShippingDocumentsCheckService(),
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        // Both rows are locked before anything else is read, so what the check reads next includes every claim and
        // every edit committed by whoever held the invoice before.
        return $this->withRowLocked(
            $this->order,
            fn (): SalesOrder => $this->withRowLocked($this->invoice, fn (): SalesOrder => $this->crossCheck())
        );
    }

    private function crossCheck(): SalesOrder
    {
        $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::SHIPPED);
        $this->assertUsable();

        // The invoice is claimed before it is compared, so a second order checked against it meanwhile is refused.
        $this->order->invoice_id = $this->invoice->getId();
        $this->order->saveOrFail();

        $this->order->documents_check = [
            'packing_list' => $this->packingList,
            'bl_draft' => $this->blDraft,
            'invoice_fingerprint' => $this->check->invoiceFingerprint($this->invoice),
            'differences' => $this->check->differences(
                $this->order,
                $this->invoice,
                $this->packingList,
                $this->blDraft
            ),
        ];
        $this->order->documents_checked_at = Carbon::now();
        $this->order->saveOrFail();

        return $this->order;
    }

    private function assertUsable(): void
    {
        if ($this->packingList === []) {
            throw new ValidationException('The packing list has no lines.');
        }

        if (! isset($this->blDraft['total_quantity'])) {
            throw new ValidationException('The draft bill of lading needs its total quantity.');
        }

        $sameCustomer = (int) $this->invoice->customer_organization_id === (int) $this->order->customer_organization_id;
        $sameTenant = (int) $this->invoice->apps_id === (int) $this->order->apps_id
            && (int) $this->invoice->companies_id === (int) $this->order->companies_id;

        if (! $sameCustomer || ! $sameTenant) {
            throw new ValidationException("Invoice {$this->invoice->invoice_number} does not belong to this order's customer.");
        }

        if ($this->invoice->document_status === InvoiceDocumentStatusEnum::VOIDED) {
            throw new ValidationException("Invoice {$this->invoice->invoice_number} is voided.");
        }

        if ($this->invoice->document_type->isNote()) {
            throw new ValidationException(
                "{$this->invoice->invoice_number} is a {$this->invoice->document_type->label()}, "
                . 'not a commercial invoice.'
            );
        }

        $other = SalesOrder::query()
            ->fromApp($this->order->app)
            ->fromCompany($this->order->company)
            ->notDeleted()
            ->where('invoice_id', $this->invoice->getId())
            ->where('id', '!=', $this->order->getId())
            ->where('document_status', '!=', SalesOrderStatusEnum::CANCELLED->value)
            ->first();

        if ($other !== null) {
            $invoice = $this->invoice->invoice_number ?? 'This draft invoice';

            throw new ValidationException("{$invoice} already covers sales order {$other->order_number}.");
        }
    }
}
