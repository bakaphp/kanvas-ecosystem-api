<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportFiscalPeriod;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Scribe\Ledger\Actions\OpenFiscalPeriodAction;
use Kanvas\Users\Models\Users;
use RuntimeException;

/**
 * Pull Acumatica financial periods (dbo.FinPeriod) into Scribe as OPEN fiscal periods.
 *
 * They're opened (not mirrored as closed) on purpose: PostJournalEntryAction gates on an OPEN
 * period, and this backfill has to be able to post historical GL into them. Closing periods to
 * match Acumatica's state is a deliberate, later admin step — never a side effect of import.
 * OpenFiscalPeriodAction is idempotent on exact bounds, so re-runs are safe.
 */
class PullFiscalPeriodsAction
{
    /** @var array<string, int> per-run skip breakdown */
    public array $skipped = [];

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
    ) {
    }

    public function execute(): int
    {
        return $this->processRows($this->fetchRows());
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchRows(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('FinPeriod')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->select(['FinPeriodID', 'StartDate', 'EndDate'])
            ->distinct()
            ->orderBy('FinPeriodID');

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     */
    public function processRows(array $rows): int
    {
        $count = 0;
        $this->skipped = [
            'no_dates' => 0,
            'overlap' => 0,
        ];
        $seen = [];

        foreach ($rows as $row) {
            $period = AcumaticaImportFiscalPeriod::from($row);

            if ($period->start === null || $period->end === null) {
                $this->skipped['no_dates']++;

                continue;
            }

            // FinPeriod carries one row per branch/organization — collapse to the period id.
            if (isset($seen[$period->periodId])) {
                continue;
            }
            $seen[$period->periodId] = true;

            try {
                new OpenFiscalPeriodAction(
                    app: $this->app,
                    company: $this->company,
                    periodStart: $period->start,
                    periodEnd: $period->end,
                    user: $this->user,
                )->execute();

                $count++;
            } catch (RuntimeException) {
                // Overlap with a differently-bounded existing period (e.g. re-derived exclusive end).
                $this->skipped['overlap']++;
            }
        }

        return $count;
    }
}
