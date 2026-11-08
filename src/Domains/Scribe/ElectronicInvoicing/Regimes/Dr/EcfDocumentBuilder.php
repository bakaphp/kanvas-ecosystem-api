<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Baka\Support\Money;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfExport;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\BuyerTaxId;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfExchangeRate;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;

/**
 * Scribe invoice / credit note / debit note → provider-neutral EcfDocument. Every DR business rule that
 * is not a provider's field mapping lives here once: line ITBIS rate, totals, the note reference. Bills
 * and expenses (E41/E43/E47) go to PurchaseEcfDocumentBuilder, which reuses the rate rules below.
 *
 * Amounts are what Scribe booked, rounded to DGII's two decimals and never recomputed, so the e-CF can't
 * drift a cent from the journal entry.
 */
class EcfDocumentBuilder
{
    public const int MODIFICATION_CANCELS = 1;
    public const int MODIFICATION_AMOUNTS = 3;

    public const int DESCRIPTION_MAX = 80;
    public const int CODE_MAX = 35;

    public function __construct(
        protected readonly EcfTypeResolver $typeResolver = new EcfTypeResolver(),
    ) {
    }

    /**
     * Any document that carries an e-CF, whichever builder knows its shape.
     */
    public function buildFor(
        Invoice|Bill|Expense $document,
        ?EcfTypeEnum $type = null,
        ?string $encf = null,
        ?string $expiresAt = null,
        ?string $systemRef = null,
    ): EcfDocument {
        return $document instanceof Invoice
            ? $this->build(
                $document,
                type: $type,
                encf: $encf,
                expiresAt: $expiresAt,
                systemRef: $systemRef,
            )
            : new PurchaseEcfDocumentBuilder()->build(
                $document,
                type: $type,
                encf: $encf,
                expiresAt: $expiresAt,
                systemRef: $systemRef,
            );
    }

    /**
     * type / encf / expiresAt / systemRef default to the invoice's current fiscal row. Pass them explicitly
     * to build the document before a number exists, so it can be validated before one is spent.
     */
    public function build(
        Invoice $invoice,
        ?EcfTypeEnum $type = null,
        ?string $encf = null,
        ?string $expiresAt = null,
        ?string $systemRef = null,
    ): EcfDocument {
        $compliance = EcfCompliance::read($invoice);
        $type ??= EcfTypeEnum::tryFrom((string) ($compliance['type'] ?? '')) ?? $this->typeResolver->resolve($invoice);
        $lines = $this->lines($invoice, $type);

        return new EcfDocument(
            type: $type,
            encf: $encf ?? (string) ($compliance['encf'] ?? ''),
            sequenceExpiresAt: $expiresAt ?? (string) ($compliance['expires_at'] ?? ''),
            issueDate: ($invoice->issued_date ?? Carbon::today())->toDateString(),
            systemRef: $systemRef ?? (string) ($compliance['fiscal_uuid'] ?? ''),
            buyer: $this->buyer($invoice),
            currency: strtoupper((string) $invoice->currency),
            exchangeRate: EcfExchangeRate::declared($invoice),
            isCredit: ! $invoice->isCreditNote(),
            lines: $lines,
            totals: self::totalsOf($lines),
            reference: $this->reference($invoice),
            purchaseOrderNumber: $type === EcfTypeEnum::GUBERNAMENTAL ? $this->purchaseOrderNumber($invoice) : null,
            export: $type === EcfTypeEnum::EXPORTACION ? $this->export($compliance) : null,
        );
    }

    /**
     * Government buyers (E45) quote their purchase order; it rides on do_ecf, or on the invoice metadata
     * where the PO number already lives for other customers.
     */
    private function purchaseOrderNumber(Invoice $invoice): ?string
    {
        $compliance = EcfCompliance::read($invoice);

        return Str::trimToNull((string) ($compliance['purchase_order_number'] ?? ($invoice->metadata ?? [])['purchase_order_number'] ?? ''));
    }

    /**
     * @param array<string, mixed> $compliance
     */
    private function export(array $compliance): ?EcfExport
    {
        $export = (array) ($compliance['export'] ?? []);
        $incoterm = Str::trimToNull((string) ($export['incoterm'] ?? ''));
        $country = Str::trimToNull((string) ($export['destination_country'] ?? ''));

        if ($incoterm === null || $country === null) {
            return null;
        }

        return new EcfExport(
            incoterm: strtoupper($incoterm),
            destinationCountry: strtoupper($country),
            estimatedForeignValue: isset($export['estimated_foreign_value']) ? Money::cents((float) $export['estimated_foreign_value']) : null,
        );
    }

    /**
     * The modified document, for a credit or debit note. modificationCode 1 when a credit note cancels the
     * parent in full, 3 when a note only changes amounts (always, for a debit note).
     */
    public function reference(Invoice $invoice): ?EcfReference
    {
        if (! $invoice->document_type->isNote()) {
            return null;
        }

        $parent = $invoice->parentInvoice;

        if ($parent === null) {
            return null;
        }

        $parentEncf = (string) (EcfCompliance::read($parent)['encf'] ?? $parent->invoice_number ?? '');
        $isFull = $invoice->isCreditNote()
            && Money::cents((float) $invoice->total_native) >= Money::cents((float) $parent->total_native);

        return new EcfReference(
            encf: $parentEncf,
            issueDate: ($parent->issued_date ?? Carbon::today())->toDateString(),
            modificationCode: $isFull ? self::MODIFICATION_CANCELS : self::MODIFICATION_AMOUNTS,
            reason: Str::trimToNull($invoice->notes),
        );
    }

    public function itbisRate(InvoiceLine $line, EcfTypeEnum $type): EcfItbisRateEnum
    {
        return self::rateFor($this->linePercent($line), $type);
    }

    public static function rateFor(float $percent, EcfTypeEnum $type): EcfItbisRateEnum
    {
        if ($type === EcfTypeEnum::EXPORTACION) {
            return EcfItbisRateEnum::RATE_0;
        }

        if ($type === EcfTypeEnum::REGIMEN_ESPECIAL) {
            return EcfItbisRateEnum::EXEMPT;
        }

        return match (true) {
            $percent <= 0.0 => EcfItbisRateEnum::EXEMPT,
            abs($percent - 16.0) < 0.5 => EcfItbisRateEnum::RATE_16,
            default => EcfItbisRateEnum::RATE_18,
        };
    }

    public function linePercent(InvoiceLine $line): float
    {
        return self::percentOf(
            $line->tax_rate,
            ($line->quantity * $line->unit_price_native) - $line->discount_amount_native,
            $line->tax_amount_native,
        );
    }

    /**
     * Rate as a percent. Scribe stores tax_rate either as a fraction (0.18) or a percent (18); with no
     * rate on the line it is derived from what was actually booked.
     */
    public static function percentOf(?float $rate, float $net, float $tax): float
    {
        if ($rate !== null && $rate > 0) {
            return $rate <= 1.0 ? $rate * 100 : $rate;
        }

        return $net > 0 && $tax > 0 ? round($tax / $net * 100, 2) : 0.0;
    }

    private function buyer(Invoice $invoice): EcfParty
    {
        $name = Str::trimToNull($invoice->billable_legal_name)
            ?? Str::trimToNull($invoice->billable_display_name)
            ?? Str::trimToNull($invoice->customer?->name)
            ?? '';

        return new EcfParty(
            name: $name,
            taxId: BuyerTaxId::of($invoice),
            isForeign: BuyerTaxId::isForeignTo($invoice, new DrFiscalRegime()),
            address: self::addressLine($invoice->billing_address_snapshot),
            email: Str::trimToNull($invoice->billable_email),
        );
    }

    public static function addressLine(?array $snapshot): ?string
    {
        if ($snapshot === null) {
            return null;
        }

        $parts = array_filter(
            array_map(
                fn ($value): string => is_scalar($value) ? trim((string) $value) : '',
                $snapshot
            ),
            fn (string $value): bool => $value !== ''
        );

        return Str::trimToNull(implode(', ', $parts));
    }

    /**
     * @return list<EcfLine>
     */
    private function lines(Invoice $invoice, EcfTypeEnum $type): array
    {
        $lines = [];

        foreach ($invoice->lines()->get() as $index => $line) {
            /** @var InvoiceLine $line */
            $lineNumber = $index + 1;
            $subtotal = self::netCents($line->quantity, $line->unit_price_native, $line->discount_amount_native);

            $lines[] = new EcfLine(
                lineNumber: $lineNumber,
                code: mb_substr(Str::trimToNull($line->sku) ?? ($line->item_id !== null ? 'ITEM-' . $line->item_id : (string) $lineNumber), 0, self::CODE_MAX),
                description: mb_substr(Str::trimToNull($line->description) ?? 'Item ' . $lineNumber, 0, self::DESCRIPTION_MAX),
                quantity: round($line->quantity, 2),
                unitPrice: round($line->unit_price_native, 4),
                itbis: $this->itbisRate($line, $type),
                isService: (bool) (($line->metadata ?? [])['is_service'] ?? true),
                discountAmount: Money::cents($line->discount_amount_native),
                subtotal: $subtotal,
                tax: Money::cents($line->tax_amount_native),
            );
        }

        return $lines;
    }

    /**
     * DGII adds up the lines it is sent, so every header amount is the sum of the cent-rounded lines, never
     * the document's own header: those round differently and the e-CF is refused.
     *
     * @param list<EcfLine> $lines
     */
    public static function totalsOf(array $lines): EcfTotals
    {
        $taxedByRate = [];
        $exempt = [];

        foreach ($lines as $line) {
            // 0% is taxed at zero (indicator 3, part of MontoGravadoTotal); only EXEMPT is MontoExento.
            if ($line->itbis !== EcfItbisRateEnum::EXEMPT) {
                $taxedByRate[$line->itbis->value][] = $line->subtotal;
            } else {
                $exempt[] = $line->subtotal;
            }
        }

        // DGII defines TotalITBISn as MontoGravadoIn × rate (Formato e-CF #101–103), not as the sum of the
        // lines' tax: the lines carry no ITBIS field, so this is the figure its cuadratura checks.
        $taxedTotal = Money::sum();
        $itbis = Money::sum();
        foreach ($taxedByRate as $rate => $subtotals) {
            $base = Money::sum(...$subtotals);
            $taxedTotal = $taxedTotal->plus($base);
            $itbis = $itbis->plus(Money::cents($base->multipliedBy((string) EcfItbisRateEnum::from((string) $rate)->percent())->dividedBy(100, 4)));
        }

        $exemptTotal = Money::sum(...$exempt);

        return new EcfTotals(
            exempt: Money::cents($exemptTotal),
            taxed: Money::cents($taxedTotal),
            itbis: Money::cents($itbis),
            total: Money::cents($taxedTotal->plus($exemptTotal)->plus($itbis)),
            itbisWithheld: Money::cents(Money::sum(...array_map(fn (EcfLine $line): float => $line->itbisWithheld, $lines))),
            isrWithheld: Money::cents(Money::sum(...array_map(fn (EcfLine $line): float => $line->isrWithheld, $lines))),
        );
    }

    public static function netCents(float $quantity, float $unitPrice, float $discount): float
    {
        return Money::cents(Money::of($quantity)->multipliedBy(Money::of($unitPrice))->minus(Money::of($discount)));
    }
}
