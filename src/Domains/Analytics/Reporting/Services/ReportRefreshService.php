<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Companies\Models\Companies;

/**
 * Writes a definition's rows into its flat table.
 *
 * Upsert rather than truncate-and-insert: a rebuild of 65k people should not leave the Gestor
 * reading an empty table for the duration.
 */
class ReportRefreshService
{
    public const int CHUNK = 500;

    public function __construct(
        protected ReportSchemaService $schema = new ReportSchemaService(),
        protected ?ConnectionInterface $connection = null,
    ) {
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection(ReportSchemaService::CONNECTION);
    }

    /**
     * @param array<int, int>|null $ids null rebuilds every row for the company
     *
     * @return int rows written
     */
    public function refresh(
        RefreshableReportInterface $definition,
        AppInterface $app,
        Companies $company,
        ?array $ids = null
    ): int {
        $table = $this->schema->tableFor($definition, $app->getId());
        $columns = $this->writableColumns($definition);
        $now = date('Y-m-d H:i:s');

        $written = 0;
        $batch = [];

        foreach ($definition->rowsFor($app, $company, $ids) as $row) {
            $batch[] = $this->normalise($row, $columns, $definition, $company, $now);

            if (count($batch) >= self::CHUNK) {
                $written += $this->upsert($table, $batch, $definition);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $written += $this->upsert($table, $batch, $definition);
        }

        return $written;
    }

    /**
     * Rows in the report table whose source no longer produces them.
     *
     * A full rebuild is the only time this can be known, so it is the only time it runs — an
     * incremental refresh has no way to tell "deleted" from "not in this batch".
     *
     * @return int rows removed
     */
    public function pruneStale(
        RefreshableReportInterface $definition,
        AppInterface $app,
        Companies $company,
        string $startedAt
    ): int {
        return $this->connection()
            ->table($this->schema->tableFor($definition, $app->getId()))
            ->where(ReportSchemaService::TENANT_COLUMN, $company->getId())
            ->where('refreshed_at', '<', $startedAt)
            ->delete();
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    protected function upsert(string $table, array $batch, RefreshableReportInterface $definition): int
    {
        $update = array_values(array_diff(
            array_keys($batch[0]),
            [$definition->primaryKey()]
        ));

        $this->connection()->table($table)->upsert($batch, [$definition->primaryKey()], $update);

        return count($batch);
    }

    /**
     * Force every row to carry the same key set — `upsert()` builds one statement for the whole
     * batch, so a row missing a column would shift the others' values.
     *
     * @param array<string, mixed> $row
     * @param array<int, ReportColumn> $columns
     *
     * @return array<string, mixed>
     */
    protected function normalise(
        array $row,
        array $columns,
        RefreshableReportInterface $definition,
        Companies $company,
        string $now
    ): array {
        $normalised = [
            $definition->primaryKey() => $row[$definition->primaryKey()],
            ReportSchemaService::TENANT_COLUMN => $row[ReportSchemaService::TENANT_COLUMN] ?? $company->getId(),
        ];

        foreach ($columns as $column) {
            $value = $row[$column->name] ?? null;

            // A multi-valued index rejects a non-array, and MySQL will not accept PHP arrays.
            if ($column->multiValued) {
                $value = $value === null || $value === [] ? null : json_encode(array_values((array) $value));
            }

            $normalised[$column->name] = $value;
        }

        $normalised['refreshed_at'] = $now;

        return $normalised;
    }

    /**
     * @return array<int, ReportColumn>
     */
    protected function writableColumns(RefreshableReportInterface $definition): array
    {
        return array_values(array_filter(
            $definition->columns(),
            fn (ReportColumn $c) => $c->name !== $definition->primaryKey()
                && $c->name !== ReportSchemaService::TENANT_COLUMN
        ));
    }
}
