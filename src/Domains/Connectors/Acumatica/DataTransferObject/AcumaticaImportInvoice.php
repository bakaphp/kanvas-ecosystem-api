<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Spatie\LaravelData\Data;

/**
 * Maps one Acumatica AR document (dbo.ARRegister joined to BAccount for the customer code) to the
 * shape PullInvoicesAction feeds into Scribe's ImportInvoiceFromExternalAction. Header-level only:
 * a single summary line carries the document total, which is enough for AR aging (the line-level GL
 * split already lives in the imported journal entries).
 *
 * `paid` is derived as OrigDocAmt − DocBal so the imported document reflects Acumatica's current
 * balance. External id is "{DocType}-{RefNbr}" (RefNbr is only unique per doc type).
 */
class AcumaticaImportInvoice extends Data
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $refNbr,
        public readonly string $customerCode,
        public readonly string $currency,
        public readonly float $total,
        public readonly float $paid,
        public readonly DocumentTypeEnum $documentType,
        public readonly ?Carbon $issuedDate,
        public readonly ?Carbon $dueDate,
        public readonly ?string $memo,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row raw ARRegister + BAccount row (PascalCase columns)
     */
    public static function fromRow(array $row): self
    {
        $docType = trim((string) ($row['DocType'] ?? ''));
        $refNbr = trim((string) ($row['RefNbr'] ?? ''));
        $total = (float) ($row['CuryOrigDocAmt'] ?? 0);
        $balance = (float) ($row['CuryDocBal'] ?? 0);
        $memo = trim((string) ($row['DocDesc'] ?? ''));

        return new self(
            externalId: $docType . '-' . $refNbr,
            refNbr: $refNbr,
            customerCode: trim((string) ($row['AcctCD'] ?? '')),
            currency: trim((string) ($row['CuryID'] ?? '')) ?: 'USD',
            total: $total,
            paid: max($total - $balance, 0.0),
            documentType: strtoupper($docType) === 'CRM'
                ? DocumentTypeEnum::CREDIT_NOTE
                : DocumentTypeEnum::INVOICE,
            issuedDate: DateHelper::tryParseCarbon($row['DocDate'] ?? null),
            dueDate: DateHelper::tryParseCarbon($row['DueDate'] ?? null),
            memo: $memo !== '' ? $memo : null,
        );
    }
}
