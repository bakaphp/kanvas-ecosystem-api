<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Spatie\LaravelData\DataCollection;

/**
 * Track I helpers on top of EcfTestCase: ranges for the note and purchase types, an accepted E31 to
 * correct, and booked bills to issue purchase e-CFs on.
 */
abstract class EcfCompletionTestCase extends EcfTestCase
{
    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        if ($this->companyIssuesEcf()) {
            foreach ([EcfTypeEnum::NOTA_DEBITO, EcfTypeEnum::COMPRAS, EcfTypeEnum::GASTOS_MENORES, EcfTypeEnum::PAGOS_EXTERIOR] as $type) {
                $this->configureRange(
                    $type,
                    1,
                    500,
                    '2028-12-31'
                );
            }
        }
    }

    /**
     * An E31 for 1,000 + 180 ITBIS (two lines: 600 + 108, 400 + 72) that DGII accepted.
     */
    protected function acceptedInvoice(?Organization $customer = null): Invoice
    {
        $customer ??= $this->customer();
        $invoice = new IssueInvoiceAction(
            invoice: $this->draftInvoice($customer, lines: [[600.0, 108.0], [400.0, 72.0]]),
            billable: $customer,
            user: static::$cachedUser,
        )->execute();

        $invoice->lines()->orderBy('sort_order')->get()->each(function ($line, int $index): void {
            $line->description = $index === 0 ? 'Consultoría' : 'Soporte';
            $line->save();
        });

        return $this->accept($invoice);
    }

    protected function accept(Invoice|Bill $document): Invoice|Bill
    {
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($document))->handle();

        return $document->refresh();
    }

    /**
     * @param list<array{0: float, 1: float}> $lines [net, ITBIS]
     */
    protected function receivedBill(
        ?string $vendorTaxId = '00112345678',
        array $lines = [[10000.0, 1800.0]],
        ?array $regionalCompliance = null,
        string $currency = 'DOP',
    ): Bill {
        $vendor = $this->seedTestOrganization('Juan Pérez Plomería');

        $draft = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, array_map(
                    fn (array $line): BillLineData => new BillLineData(
                        description: 'Reparación de tuberías',
                        quantity: 1,
                        unit_price_native: $line[0],
                        tax_rate: $line[1] > 0 ? 18.0 : null,
                        tax_amount_native: $line[1],
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES),
                    ),
                    $lines
                )),
                currency: $currency,
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-10'),
                due_date: Carbon::parse('2026-06-30'),
                regional_compliance: $regionalCompliance,
                vendor_tax_id: $vendorTaxId,
            ),
            user: static::$cachedUser,
        )->execute();

        return new ReceiveBillAction(
            bill: $draft,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();
    }

    protected function ledgerHas(Invoice|Bill $document, string $eventType): bool
    {
        return Event::query()
            ->where('event_type', $eventType)
            ->where('source_entity_type', $document::class)
            ->where('source_entity_id', $document->getId())
            ->exists();
    }
}
