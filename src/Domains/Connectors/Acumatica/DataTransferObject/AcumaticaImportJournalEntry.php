<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Maps one Acumatica GL batch (dbo.Batch header + dbo.GLTran lines) to the shape
 * PullJournalEntriesAction feeds into Scribe's PostJournalEntryAction. Pure — account
 * resolution (AccountCD → Kanvas account id) and fiscal-period gating happen in the pull action.
 *
 * A batch is the atomic, balanced posting unit in Acumatica (SUM of base debits == SUM of base
 * credits), which is exactly the Scribe journal-entry invariant, so batch → JE is 1:1.
 *
 * @property DataCollection<AcumaticaImportJournalEntryLine> $lines
 */
class AcumaticaImportJournalEntry extends Data
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?Carbon $postedAt,
        public readonly ?string $memo,
        public readonly string $currency,
        public readonly string $finPeriodId,
        public readonly string $module,
        /** @var DataCollection<AcumaticaImportJournalEntryLine> */
        public readonly DataCollection $lines,
    ) {
    }

    /**
     * @param array<array-key, mixed>             $header raw dbo.Batch row
     * @param array<int, array<array-key, mixed>> $lines  raw dbo.GLTran rows (joined to Account for AccountCD)
     */
    public static function fromRow(array $header, array $lines): self
    {
        $description = trim((string) ($header['Description'] ?? ''));
        $currency = trim((string) ($header['CuryID'] ?? '')) ?: 'USD';

        return new self(
            externalId: trim((string) ($header['BatchNbr'] ?? '')),
            postedAt: DateHelper::tryParseCarbon($header['DateEntered'] ?? ($header['TranDate'] ?? null)),
            memo: $description !== '' ? $description : null,
            currency: $currency,
            finPeriodId: trim((string) ($header['FinPeriodID'] ?? '')),
            module: trim((string) ($header['Module'] ?? '')),
            lines: new DataCollection(AcumaticaImportJournalEntryLine::class, self::mapLines($lines, $currency)),
        );
    }

    /**
     * @param array<int, array<array-key, mixed>> $lines
     *
     * @return array<int, AcumaticaImportJournalEntryLine>
     */
    private static function mapLines(array $lines, string $batchCurrency): array
    {
        $mapped = [];

        foreach ($lines as $line) {
            $accountCd = trim((string) ($line['AccountCD'] ?? ''));
            $debitNative = (float) ($line['CuryDebitAmt'] ?? 0);
            $creditNative = (float) ($line['CuryCreditAmt'] ?? 0);
            $debitBase = (float) ($line['DebitAmt'] ?? 0);
            $creditBase = (float) ($line['CreditAmt'] ?? 0);

            // Drop no-op lines (zero on every side): Scribe's validator requires exactly one of
            // debit/credit non-zero per line, so Acumatica's statistical/memo zero lines would
            // otherwise fail the whole batch. They don't affect the balance.
            if ($accountCd === '' || ($debitNative === 0.0 && $creditNative === 0.0 && $debitBase === 0.0 && $creditBase === 0.0)) {
                continue;
            }

            $memo = trim((string) ($line['TranDesc'] ?? ''));

            $mapped[] = new AcumaticaImportJournalEntryLine(
                accountCd: $accountCd,
                debitNative: $debitNative,
                creditNative: $creditNative,
                debitBase: $debitBase,
                creditBase: $creditBase,
                currency: trim((string) ($line['CuryID'] ?? '')) ?: $batchCurrency,
                memo: $memo !== '' ? $memo : null,
            );
        }

        return $mapped;
    }
}
