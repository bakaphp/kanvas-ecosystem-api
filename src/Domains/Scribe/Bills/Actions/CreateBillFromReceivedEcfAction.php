<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Exceptions\DuplicateBillNumberException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Services\VendorByTaxIdResolverService;
use Kanvas\Scribe\ElectronicInvoicing\Actions\CreateFiscalDocumentAction;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalDocumentInterface;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalLineInterface;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * A vendor's electronic invoice → a DRAFT bill for the AP agent to code, approve and schedule, in any country.
 * Provider-neutral: each provider hands it over as its country's ReceivedFiscalDocumentInterface (DR: WebPOS pulls
 * the signed XML, Alanube pushes structured data, both as ReceivedEcf).
 *
 * The authority's facts land on a `received` fiscal row, built by the document's regime, in the same transaction.
 *
 * Idempotent on vendor tax id + number (unique at the authority), stored as source `e_invoice` + external_id, which
 * the bills table already keeps unique per app. The source names the kind of document, not the provider or the
 * country (that is on the fiscal row), so a company that switches provider doesn't book the same document twice. Lines carry no GL account:
 * coding from the vendor's history is the AP agent's job, and a guessed account is worse than an open one.
 */
class CreateBillFromReceivedEcfAction
{
    public const string SOURCE = 'e_invoice';
    public const string LEDGER_EVENT = 'scribe.bill.received_ecf_ingested';
    public const string XML_FIELD_NAME = 'received_ecf_xml';

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly ReceivedFiscalDocumentInterface $received,
    ) {
    }

    public function execute(): Bill
    {
        $regime = FiscalRegimes::for($this->received->country());

        $existing = Bill::withTrashed()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source', self::SOURCE)
            ->where('external_id', $this->received->externalId())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $vendor = $this->resolveVendor($regime);

        try {
            $bill = DB::connection('accounting')->transaction(function () use ($vendor, $regime): Bill {
                $bill = $this->createBill($vendor, $regime);

                new CreateFiscalDocumentAction(
                    entity: $bill,
                    direction: FiscalDirectionEnum::RECEIVED,
                    attributes: $regime->receivedAttributes(
                        $this->received,
                        $this->company,
                        EcfEnvironmentEnum::forCompany($this->company)->value
                    ),
                )->execute();

                return $bill;
            });
        } catch (DuplicateBillNumberException $e) {
            // The same number already came in another way (the accounting inbox PDF). That bill is the one.
            if ($e->existing === null) {
                throw $e;
            }

            return $e->existing;
        }

        $this->attachSignedXml($bill);

        $bill->emitLedgerEvent(
            eventType: self::LEDGER_EVENT,
            payload: [
                'encf' => $this->received->number(),
                'ecf_type' => $this->received->documentType()->code(),
                'country' => $regime->country(),
                'vendor_rnc' => $this->received->vendorTaxId(),
                'vendor_organization_id' => $vendor?->getId(),
                'currency' => $this->received->currency(),
                'total_native' => $bill->total_native,
                'provider' => $this->received->provider(),
            ],
        );

        return $bill;
    }

    private function createBill(?Organization $vendor, FiscalRegimeInterface $regime): Bill
    {
        return new CreateBillAction(
            data: new BillData(
                app: $this->app,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, array_map(
                    fn (ReceivedFiscalLineInterface $line): BillLineData => new BillLineData(
                        description: $line->description(),
                        quantity: $line->quantity(),
                        unit_price_native: $line->unitPrice(),
                        sku: $line->code(),
                        sort_order: $line->lineNumber(),
                        discount_amount_native: $line->discountAmount(),
                        tax_rate: $line->taxRate(),
                        tax_amount_native: $line->taxAmount(),
                        tax_metadata: $line->taxMetadata(),
                    ),
                    $this->received->lines(),
                )),
                currency: $this->received->currency(),
                fx_rate_to_base: $this->received->exchangeRate(),
                bill_number: $this->received->number(),
                bill_date: Carbon::parse($this->received->issueDate()),
                received_date: Carbon::today(),
                due_date: $this->received->dueDate() !== null ? Carbon::parse($this->received->dueDate()) : null,
                notes: "Received {$regime->authority()} electronic invoice {$this->received->number()} from {$this->received->vendorName()}.",
                metadata: [
                    'received_ecf' => [
                        'provider' => $this->received->provider(),
                        'vendor_rnc' => $this->received->vendorTaxId(),
                        'totals' => $this->received->totalsSummary(),
                        'xml_sha256' => $this->received->rawXml() !== null ? hash('sha256', $this->received->rawXml()) : null,
                    ],
                ],
                source: self::SOURCE,
                external_id: $this->received->externalId(),
                vendor_display_name: $vendor?->name ?? $this->received->vendorName(),
                vendor_legal_name: $this->received->vendorName(),
                vendor_tax_id: $this->received->vendorTaxId(),
            ),
            user: $this->user,
        )->execute();
    }

    /**
     * Null leaves the bill vendor-less (with the snapshot) for the agent rather than booked against the wrong one.
     */
    private function resolveVendor(FiscalRegimeInterface $regime): ?Organization
    {
        return new VendorByTaxIdResolverService()->resolveOrCreate(
            $this->app,
            $this->company,
            $this->user,
            $this->received->vendorTaxId(),
            [
                'vendor_name' => $this->received->vendorName(),
                'vendor_tax_id' => $this->received->vendorTaxId(),
            ],
            taxIdType: TaxIdTypeEnum::infer($regime->country(), (string) Str::normalizeTaxId($this->received->vendorTaxId())),
        );
    }

    /**
     * The signed XML is the legal document (what the 606 and an audit rely on), so it is kept as a file on
     * the bill. Best-effort: a storage hiccup must not lose the bill, the provider can serve the XML again.
     */
    private function attachSignedXml(Bill $bill): void
    {
        if ($this->received->rawXml() === null || ! $this->user instanceof Users) {
            return;
        }

        try {
            $file = new FilesystemServices($bill->app, $this->company)->uploadContents(
                $this->received->rawXml(),
                'e-cf-' . $this->received->number() . '.xml',
                $this->user
            );

            $bill->addFile($file, self::XML_FIELD_NAME);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
