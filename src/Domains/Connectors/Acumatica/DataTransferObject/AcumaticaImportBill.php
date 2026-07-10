<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Maps one Acumatica AP document (dbo.APRegister joined to BAccount for the vendor code) to the
 * shape PullBillsAction feeds into Scribe's ImportBillFromExternalAction. Header-level only: a
 * single summary line carries the document total (the line-level GL split lives in the imported
 * journal entries).
 *
 * `paid` is derived as OrigDocAmt − DocBal. External id is "{DocType}-{RefNbr}".
 */
class AcumaticaImportBill extends Data
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $refNbr,
        public readonly string $vendorCode,
        public readonly string $currency,
        public readonly float $total,
        public readonly float $paid,
        public readonly ?Carbon $billDate,
        public readonly ?Carbon $dueDate,
        public readonly ?string $memo,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row raw APRegister + BAccount row (PascalCase columns)
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
            vendorCode: trim((string) ($row['AcctCD'] ?? '')),
            currency: trim((string) ($row['CuryID'] ?? '')) ?: 'USD',
            total: $total,
            paid: max($total - $balance, 0.0),
            billDate: DateHelper::tryParseCarbon($row['DocDate'] ?? null),
            dueDate: DateHelper::tryParseCarbon($row['DueDate'] ?? null),
            memo: $memo !== '' ? $memo : null,
        );
    }
}
