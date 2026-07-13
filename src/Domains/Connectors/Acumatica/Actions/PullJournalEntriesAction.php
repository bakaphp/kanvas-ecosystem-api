<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportAccount;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportJournalEntry;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportJournalEntryLine;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Connectors\Acumatica\Traits\ResolvesAcumaticaGlCoding;
use Kanvas\Scribe\Ledger\Actions\OpenFiscalPeriodAction;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntry as JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine as JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Exceptions\ClosedFiscalPeriodException;
use Kanvas\Scribe\Ledger\Exceptions\UnbalancedJournalEntryException;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\PeriodCloseService;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use stdClass;
use Throwable;

/**
 * Pull posted Acumatica GL batches (dbo.Batch + dbo.GLTran) into Scribe as journal entries.
 *
 * Acumatica's GL is authoritative for imported books, so each batch posts straight through
 * PostJournalEntryAction with origin=EXTERNAL — never re-derived from Kanvas sub-ledgers. Missing
 * accounts and fiscal periods are created on the fly so the pull is self-sufficient. Dedupe is on
 * the fully-qualified "{module}-{batchNbr}" external id, since BatchNbr is only unique per module.
 */
class PullJournalEntriesAction
{
    use ResolvesAcumaticaGlCoding;

    /** @var array<string, int> per-run skip breakdown */
    public array $skipped = [];

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
        protected ?Carbon $modifiedSince = null,
        protected PeriodCloseService $periods = new PeriodCloseService(),
    ) {
    }

    public function execute(): int
    {
        $headers = $this->fetchHeaders();

        $batchNbrs = array_values(array_unique(array_filter(array_map(
            static fn (array $h): string => trim((string) ($h['BatchNbr'] ?? '')),
            $headers
        ))));

        return $this->processRows(
            $headers,
            $this->fetchLinesByBatch($batchNbrs)
        );
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchHeaders(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('Batch')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->where('Released', 1)
            ->select([
                'Module', 'BatchNbr', 'DateEntered', 'FinPeriodID',
                'CuryID', 'Description', 'Status',
            ])
            ->orderByDesc('DateEntered');

        if ($this->modifiedSince !== null) {
            $query->where('LastModifiedDateTime', '>', $this->modifiedSince);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(
            fn (stdClass $row): array => (array) $row,
            $query->get()->all()
        );
    }

    /**
     * @param array<int, string> $batchNbrs
     *
     * @return array<string, array<int, array<array-key, mixed>>> keyed by "Module-BatchNbr"
     */
    protected function fetchLinesByBatch(array $batchNbrs): array
    {
        if ($batchNbrs === []) {
            return [];
        }

        $rows = SqlClient::connection($this->app)
            ->table('GLTran as t')
            ->join('Account as a', function (JoinClause $join): void {
                $join->on('a.AccountID', '=', 't.AccountID')
                    ->on('a.CompanyID', '=', 't.CompanyID');
            })
            ->leftJoin('Sub as s', function (JoinClause $join): void {
                $join->on('s.SubID', '=', 't.SubID')
                    ->on('s.CompanyID', '=', 't.CompanyID');
            })
            ->where('t.CompanyID', $this->acumaticaCompanyId)
            ->whereIn('t.BatchNbr', $batchNbrs)
            // GLTran carries no currency code (CuryID lives on the Batch header); the mapper
            // falls the line currency back to the batch currency.
            ->select([
                't.Module', 't.BatchNbr', 'a.AccountCD', 's.SubCD', 't.TranDesc',
                't.DebitAmt', 't.CreditAmt', 't.CuryDebitAmt', 't.CuryCreditAmt',
            ])
            ->orderBy('t.LineNbr')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $key = trim((string) $row->Module) . '-' . trim((string) $row->BatchNbr);
            $grouped[$key][] = (array) $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<array-key, mixed>>                $headers
     * @param array<string, array<int, array<array-key, mixed>>> $linesByBatch
     */
    public function processRows(array $headers, array $linesByBatch): int
    {
        $count = 0;
        $this->skipped = [
            'no_batch_number' => 0,
            'no_lines' => 0,
            'already_exists' => 0,
            'missing_account' => 0,
            'no_period' => 0,
            'unbalanced' => 0,
            'post_failed' => 0,
        ];

        foreach ($headers as $header) {
            $module = trim((string) ($header['Module'] ?? ''));
            $batchNbr = trim((string) ($header['BatchNbr'] ?? ''));
            $key = $module . '-' . $batchNbr;

            $je = AcumaticaImportJournalEntry::from($header, $linesByBatch[$key] ?? []);
            $externalId = $key;

            if ($batchNbr === '' || $je->postedAt === null) {
                $this->skipped['no_batch_number']++;

                continue;
            }

            if ($je->lines->count() === 0) {
                $this->skipped['no_lines']++;

                continue;
            }

            if ($this->alreadyImported($externalId)) {
                $this->skipped['already_exists']++;

                continue;
            }

            $lines = $this->buildLines($je->lines);

            if ($lines === null) {
                $this->skipped['missing_account']++;

                continue;
            }

            $this->ensurePeriod($je->postedAt);

            try {
                new PostJournalEntryAction(
                    new JournalEntryData(
                        app: $this->app,
                        company: $this->company,
                        postedAt: $je->postedAt,
                        sourceType: 'acumatica_gl',
                        lines: new DataCollection(JournalEntryLineData::class, $lines),
                        sourceExternalId: $externalId,
                        memo: $je->memo,
                        source: AcumaticaImportAccount::SOURCE,
                        externalId: $externalId,
                        origin: JournalEntryOriginEnum::EXTERNAL,
                        metadata: ['module' => $je->module, 'fin_period_id' => $je->finPeriodId],
                    ),
                    $this->user,
                )->execute();

                $count++;
            } catch (UnbalancedJournalEntryException) {
                $this->skipped['unbalanced']++;
            } catch (ClosedFiscalPeriodException) {
                $this->skipped['no_period']++;
            } catch (Throwable) {
                $this->skipped['post_failed']++;
            }
        }

        return $count;
    }

    /**
     * @param DataCollection<int, AcumaticaImportJournalEntryLine> $lines
     *
     * @return array<int, JournalEntryLineData>|null null when any line's account can't be resolved
     */
    private function buildLines(DataCollection $lines): ?array
    {
        $built = [];

        foreach ($lines as $line) {
            $accountId = $this->resolveAccountId($line->accountCd);

            if ($accountId === null) {
                return null;
            }

            $built[] = new JournalEntryLineData(
                account_id: $accountId,
                subaccount_id: $this->resolveSubaccountId($line->subCode),
                debit_native: $line->debitNative,
                credit_native: $line->creditNative,
                debit_base: $line->debitBase,
                credit_base: $line->creditBase,
                currency: $line->currency,
                fx_rate_to_base: $this->deriveFxRate($line),
                memo: $line->memo,
            );
        }

        return $built;
    }

    private function deriveFxRate(AcumaticaImportJournalEntryLine $line): float
    {
        $base = $line->debitBase + $line->creditBase;
        $native = $line->debitNative + $line->creditNative;

        if ($native === 0.0) {
            return 1.0;
        }

        return round($base / $native, 10);
    }

    private function ensurePeriod(Carbon $postedAt): void
    {
        if ($this->periods->findPeriodFor($this->app->getId(), $this->company->getId(), $postedAt) !== null) {
            return;
        }

        try {
            new OpenFiscalPeriodAction(
                app: $this->app,
                company: $this->company,
                periodStart: $postedAt->copy()->startOfMonth(),
                periodEnd: $postedAt->copy()->endOfMonth(),
                user: $this->user,
            )->execute();
        } catch (RuntimeException) {
            // Overlaps a non-calendar fiscal period edge — PostJournalEntryAction will surface
            // the no-period case as a skip counter.
        }
    }

    private function alreadyImported(string $externalId): bool
    {
        return JournalEntry::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('external_id', $externalId)
            ->where('origin', JournalEntryOriginEnum::EXTERNAL->value)
            ->exists();
    }
}
