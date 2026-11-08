<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Spatie\LaravelData\Data;

/**
 * Provider-neutral e-CF, built from a Scribe document by EcfDocumentBuilder. Providers only map it.
 *
 * systemRef is the send attempt's uuid (FiscalDocumentSubmission): the provider dedupes on it, so a status query
 * about an attempt never issues twice and a new attempt is never mistaken for an earlier one.
 * Dates are Y-m-d strings so the DTO stays plain data.
 *
 * purchaseOrderNumber (E45), export (E46) and foreignPayment (E47) are type-specific and null elsewhere;
 * they were added after the first providers, so they default to null and older callers keep compiling.
 */
class EcfDocument extends Data
{
    /**
     * @param EcfLine[] $lines
     */
    public function __construct(
        public readonly EcfTypeEnum $type,
        public readonly string $encf,
        public readonly string $sequenceExpiresAt,
        public readonly string $issueDate,
        public readonly string $systemRef,
        public readonly EcfParty $buyer,
        public readonly string $currency,
        public readonly float $exchangeRate,
        public readonly bool $isCredit,
        public readonly array $lines,
        public readonly EcfTotals $totals,
        public readonly ?EcfReference $reference = null,
        public readonly ?string $purchaseOrderNumber = null,
        public readonly ?EcfExport $export = null,
        public readonly ?EcfForeignPayment $foreignPayment = null,
    ) {
    }
}
