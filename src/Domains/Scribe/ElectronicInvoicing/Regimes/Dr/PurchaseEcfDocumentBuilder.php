<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Baka\Support\Money;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfForeignPayment;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject\EcfParty;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfExchangeRate;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseLine;

/**
 * Bill / expense → the e-CF the company itself issues for what it bought or paid: E41 (purchase from a
 * supplier who issues no e-CF), E43 (minor expenses), E47 (payment to a foreign payee). In these the
 * "buyer" block is the counterparty — the supplier or payee — because DGII's schema reuses it.
 *
 * Withholdings are facts about the deal, not something to infer: `do_ecf.withholding` on the document
 * gives `itbis_percent` (of each line's ITBIS) and `isr_percent` (of each line's net), and a line's own
 * `metadata.itbis_withheld` / `isr_withheld` wins over the percentages. `do_ecf.foreign_payment` carries the
 * E47 payment details.
 */
class PurchaseEcfDocumentBuilder
{
    public function build(
        Bill|Expense $document,
        ?EcfTypeEnum $type = null,
        ?string $encf = null,
        ?string $expiresAt = null,
        ?string $systemRef = null,
    ): EcfDocument {
        $compliance = EcfCompliance::read($document);
        $type ??= EcfTypeEnum::from((string) ($compliance['type'] ?? EcfTypeEnum::COMPRAS->value));
        $lines = $this->lines($document, $type, (array) ($compliance['withholding'] ?? []));

        return new EcfDocument(
            type: $type,
            encf: $encf ?? (string) ($compliance['encf'] ?? ''),
            sequenceExpiresAt: $expiresAt ?? (string) ($compliance['expires_at'] ?? ''),
            issueDate: (string) ($compliance['issue_date'] ?? Carbon::today()->toDateString()),
            systemRef: $systemRef ?? (string) ($compliance['fiscal_uuid'] ?? ''),
            buyer: $this->counterparty($document, $type, $compliance),
            currency: strtoupper((string) $document->currency),
            exchangeRate: EcfExchangeRate::declared($document),
            isCredit: $document instanceof Bill && $document->document_status !== BillDocumentStatusEnum::PAID,
            lines: $lines,
            totals: EcfDocumentBuilder::totalsOf($lines),
            foreignPayment: $type === EcfTypeEnum::PAGOS_EXTERIOR ? $this->foreignPayment($compliance) : null,
        );
    }

    /**
     * @param array<string, mixed> $compliance
     */
    private function counterparty(Bill|Expense $document, EcfTypeEnum $type, array $compliance): EcfParty
    {
        $isForeign = $type === EcfTypeEnum::PAGOS_EXTERIOR || (bool) ($compliance['foreign_payee'] ?? false);
        $rawTaxId = $document->vendor_tax_id ?? $document->vendor?->getPayeeTaxId();
        $taxId = $isForeign
            ? Str::trimToNull((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $rawTaxId))
            : Str::trimToNull(Str::digitsOnly((string) $rawTaxId));

        return new EcfParty(
            name: Str::trimToNull($document->vendor_legal_name)
                ?? Str::trimToNull($document->vendor_display_name)
                ?? Str::trimToNull($document->vendor?->name)
                ?? '',
            taxId: $taxId,
            isForeign: $isForeign,
            address: $document instanceof Bill ? EcfDocumentBuilder::addressLine($document->vendor_address_snapshot) : null,
            email: Str::trimToNull($document->vendor_email),
        );
    }

    /**
     * @param array<array-key, mixed> $withholding
     *
     * @return list<EcfLine>
     */
    private function lines(Bill|Expense $document, EcfTypeEnum $type, array $withholding): array
    {
        $lines = [];

        foreach ($document->lines()->get() as $index => $line) {
            /** @var BillLine|ExpenseLine $line */
            $lineNumber = $index + 1;

            if ($line instanceof BillLine) {
                $quantity = (float) $line->quantity;
                $unitPrice = (float) $line->unit_price_native;
                $discount = (float) $line->discount_amount_native;
                $net = Money::of($quantity)->multipliedBy(Money::of($unitPrice))->minus(Money::of($discount))->toFloat();
                $rate = $line->tax_rate;
                $code = Str::trimToNull($line->sku) ?? ($line->item_id !== null ? 'ITEM-' . $line->item_id : (string) $lineNumber);
            } else {
                $quantity = 1.0;
                $unitPrice = (float) $line->amount_native;
                $discount = 0.0;
                $net = $unitPrice;
                $rate = null;
                $code = $line->item_id !== null ? 'ITEM-' . $line->item_id : (string) $lineNumber;
            }

            $tax = Money::cents((float) $line->tax_amount_native);
            $subtotal = EcfDocumentBuilder::netCents($quantity, $unitPrice, $discount);
            $metadata = $line->metadata ?? [];

            $lines[] = new EcfLine(
                lineNumber: $lineNumber,
                code: mb_substr($code, 0, EcfDocumentBuilder::CODE_MAX),
                description: mb_substr(Str::trimToNull($line->description) ?? 'Item ' . $lineNumber, 0, EcfDocumentBuilder::DESCRIPTION_MAX),
                quantity: round($quantity, 2),
                unitPrice: round($unitPrice, 4),
                itbis: EcfDocumentBuilder::rateFor(EcfDocumentBuilder::percentOf($rate, $net, $tax), $type),
                isService: (bool) ($metadata['is_service'] ?? true),
                discountAmount: Money::cents($discount),
                subtotal: $subtotal,
                tax: $tax,
                itbisWithheld: $this->withheld($metadata['itbis_withheld'] ?? null, $tax, $withholding['itbis_percent'] ?? null),
                isrWithheld: $this->withheld($metadata['isr_withheld'] ?? null, $subtotal, $withholding['isr_percent'] ?? null),
            );
        }

        return $lines;
    }

    private function withheld(mixed $explicit, float $base, mixed $percent): float
    {
        if (is_numeric($explicit)) {
            return Money::cents((float) $explicit);
        }

        return is_numeric($percent)
            ? Money::cents(Money::of($base)->multipliedBy(Money::of((float) $percent))->dividedBy(100, 6))
            : 0.0;
    }

    /**
     * @param array<string, mixed> $compliance
     */
    private function foreignPayment(array $compliance): ?EcfForeignPayment
    {
        $payment = (array) ($compliance['foreign_payment'] ?? []);

        if ($payment === []) {
            return null;
        }

        return new EcfForeignPayment(
            accountType: Str::trimToNull((string) ($payment['account_type'] ?? '')),
            accountNumber: Str::trimToNull((string) ($payment['account_number'] ?? '')),
            bank: Str::trimToNull((string) ($payment['bank'] ?? '')),
            foreignCurrency: Str::trimToNull(strtoupper((string) ($payment['currency'] ?? ''))),
            foreignAmount: isset($payment['amount']) ? Money::cents((float) $payment['amount']) : null,
        );
    }
}
