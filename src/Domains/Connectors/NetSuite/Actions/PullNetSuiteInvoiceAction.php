<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Concerns\ResolvesNetSuiteCurrency;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteInvoiceService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Actions\ImportInvoiceFromExternalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Souk\Orders\Models\Order;
use NetSuite\Classes\Invoice as NetSuiteInvoice;
use Spatie\LaravelData\DataCollection;

/**
 * A NetSuite invoice as a Scribe invoice, linked to the Souk order of the sales order it was billed from.
 * Imported, not issued: NetSuite holds the books, so no journal entry (ImportInvoiceFromExternalAction).
 * Invoice totals must match before saving; the customer, products and order are imported separately.
 */
class PullNetSuiteInvoiceAction
{
    use ResolvesNetSuiteCurrency;

    public const string SOURCE = 'netsuite';

    public ?Order $order = null;

    protected NetSuiteInvoiceService $invoiceService;
    protected PullNetSuiteSalesOrderToOrderAction $salesOrderAction;
    protected SyncNetSuiteCustomerWithOrganizationAction $customerAction;

    public function __construct(protected Apps $app, protected Companies $company, protected UserInterface $user)
    {
        $this->invoiceService = new NetSuiteInvoiceService($app, $company);
        $this->salesOrderAction = new PullNetSuiteSalesOrderToOrderAction($app, $company, $user);
        $this->customerAction = new SyncNetSuiteCustomerWithOrganizationAction($app, $company, $user);
    }

    /** Barcodes the sales order pull had to add to the catalog. */
    public function pulledProducts(): array
    {
        return $this->salesOrderAction->pulledProducts;
    }

    /** Barcodes whose deleted product the sales order pull brought back. */
    public function restoredProducts(): array
    {
        return $this->salesOrderAction->restoredProducts;
    }

    public function executeByNumber(string $invoiceNumber): Invoice
    {
        return $this->import($this->invoiceService->getInvoiceByNumber($invoiceNumber));
    }

    public function executeById(int|string $internalId): Invoice
    {
        return $this->import($this->invoiceService->getInvoiceById($internalId));
    }

    protected function import(NetSuiteInvoice $netsuiteInvoice): Invoice
    {
        $createdFrom = Str::trimToNull((string) ($netsuiteInvoice->createdFrom->internalId ?? ''));
        $this->order = $createdFrom !== null ? $this->salesOrderAction->execute($createdFrom) : null;

        $data = new InvoiceData(
            app: $this->app,
            company: $this->company,
            billable: $this->customerAction->execute((string) $netsuiteInvoice->entity->internalId),
            lines: new DataCollection(InvoiceLineData::class, $this->lines($netsuiteInvoice)),
            currency: $this->getCurrencyModel((string) ($netsuiteInvoice->currencyName ?? 'USD'))->code,
            fx_rate_to_base: (float) ($netsuiteInvoice->exchangeRate ?? 1.0) ?: 1.0,
            invoice_number: (string) $netsuiteInvoice->tranId,
            issued_date: $this->date($netsuiteInvoice->tranDate),
            due_date: $this->date($netsuiteInvoice->dueDate),
            notes: Str::trimToNull($netsuiteInvoice->memo),
            terms: Str::trimToNull($netsuiteInvoice->terms->name ?? null),
            order: $this->order,
            metadata: array_filter([
                'po_number' => Str::trimToNull($netsuiteInvoice->otherRefNum),
                'memo' => Str::trimToNull($netsuiteInvoice->memo),
                'location' => Str::trimToNull($netsuiteInvoice->location->name ?? null),
                'netsuite_status' => Str::trimToNull($netsuiteInvoice->status),
            ]),
            source: self::SOURCE,
            external_id: (string) $netsuiteInvoice->internalId,
            external_url: $this->recordUrl((string) $netsuiteInvoice->internalId),
            origin: JournalEntryOriginEnum::EXTERNAL,
        );

        $this->assertTotalMatches($data, $netsuiteInvoice);

        return new ImportInvoiceFromExternalAction(
            data: $data,
            paidNative: $this->paid($netsuiteInvoice),
            user: $this->user,
        )->execute();
    }

    /**
     * Every NetSuite line becomes one Scribe line at the rate NetSuite charged (not the item's base
     * price). A line without a quantity (a discount or charge item) is one unit of its amount.
     *
     * @return list<InvoiceLineData>
     */
    protected function lines(NetSuiteInvoice $netsuiteInvoice): array
    {
        $lines = [];

        foreach (array_values(Arr::wrap($netsuiteInvoice->itemList->item ?? null)) as $index => $item) {
            $barcode = Str::trimToNull($item->item->name ?? null);
            $hasQuantity = $item->quantity !== null && (float) $item->quantity != 0.0;

            $lines[] = new InvoiceLineData(
                description: Str::trimToNull($item->description ?? null) ?? $barcode ?? 'NetSuite line ' . ($index + 1),
                quantity: $hasQuantity ? (float) $item->quantity : 1.0,
                unit_price_native: $hasQuantity ? (float) ($item->rate ?? 0) : (float) ($item->amount ?? 0),
                sku: $barcode,
                sort_order: $index,
                tax_amount_native: (float) ($item->taxAmount ?? 0),
            );
        }

        if ((float) ($netsuiteInvoice->shippingCost ?? 0) > 0) {
            $lines[] = new InvoiceLineData(
                description: 'Shipping',
                quantity: 1.0,
                unit_price_native: (float) $netsuiteInvoice->shippingCost,
                sort_order: count($lines),
            );
        }

        return $lines;
    }

    protected function assertTotalMatches(InvoiceData $data, NetSuiteInvoice $netsuiteInvoice): void
    {
        $kanvasTotal = (float) $data->totals()[0]['total'];
        $netsuiteTotal = (float) ($netsuiteInvoice->total ?? 0);

        if (abs($kanvasTotal - $netsuiteTotal) > 0.01) {
            throw new ValidationException(sprintf(
                'NetSuite invoice %s totals %.2f but its lines add up to %.2f in Kanvas; the invoice was not imported',
                $netsuiteInvoice->tranId,
                $netsuiteTotal,
                $kanvasTotal
            ));
        }
    }

    protected function paid(NetSuiteInvoice $netsuiteInvoice): float
    {
        if ($netsuiteInvoice->amountPaid !== null) {
            return (float) $netsuiteInvoice->amountPaid;
        }

        return max((float) ($netsuiteInvoice->total ?? 0) - (float) ($netsuiteInvoice->amountRemaining ?? 0), 0.0);
    }

    protected function date(?string $value): ?Carbon
    {
        return Str::trimToNull($value) !== null ? Carbon::parse($value)->startOfDay() : null;
    }

    /**
     * NetSuite's UI host is the account id lowercased with `_` as `-` (sandbox `123_SB1` → `123-sb1`).
     */
    protected function recordUrl(string $internalId): ?string
    {
        $account = $this->app->get(ConfigurationEnum::NET_SUITE_ACCOUNT_CONFIG->value)['account'] ?? null;

        if (Str::trimToNull((string) $account) === null) {
            return null;
        }

        return 'https://' . strtolower(str_replace('_', '-', (string) $account))
            . '.app.netsuite.com/app/accounting/transactions/custinvc.nl?id=' . $internalId;
    }
}
