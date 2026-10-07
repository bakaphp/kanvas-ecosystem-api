<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;

/**
 * Creates and evolves a definition's flat table.
 *
 * Not migrations: the tables are per-app (`rpt_{model}_app{id}`), so there is no fixed set to
 * migrate. This diffs the definition against `information_schema` and emits the difference,
 * which makes it safe to run on every deploy.
 *
 * **Additive by default.** A column the definition dropped is left in place unless `prune` is
 * asked for — a report table is downstream of everything, and silently dropping a column takes a
 * Gestor filter and an agent's schema with it.
 */
class ReportSchemaService
{
    public const string CONNECTION = 'reporting';

    /**
     * Columns every table carries regardless of definition.
     *
     * `companies_id` is the tenant discriminator and leads every index. There is deliberately no
     * `apps_id`: the app is in the table name, so a column would be constant in every row and
     * dead weight at the front of each index.
     */
    public const string TENANT_COLUMN = 'companies_id';

    /**
     * Type changes declined because they would narrow a column. Read by the command so a skip is
     * reported rather than silently doing nothing.
     *
     * @var array<int, string>
     */
    protected array $skipped = [];

    public function __construct(
        protected ?ConnectionInterface $connection = null,
    ) {
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection ?? DB::connection(self::CONNECTION);
    }

    public function tableFor(ReportDefinitionInterface $definition, int $appId): string
    {
        return sprintf('rpt_%s_app%d', $definition->model(), $appId);
    }

    /**
     * Bring the table in line with the definition.
     *
     * @return array<int, string> the statements executed, for the command to echo
     */
    public function sync(
        ReportDefinitionInterface $definition,
        int $appId,
        bool $prune = false,
        bool $allowNarrowing = false
    ): array {
        $table = $this->tableFor($definition, $appId);

        if (! $this->tableExists($table)) {
            $sql = $this->createStatement($definition, $table);
            $this->connection()->statement($sql);

            return [$sql];
        }

        $statements = $this->alterStatements($definition, $table, $prune, $allowNarrowing);

        foreach ($statements as $sql) {
            $this->connection()->statement($sql);
        }

        return $statements;
    }

    public function tableExists(string $table): bool
    {
        return $this->connection()->getSchemaBuilder()->hasTable($table);
    }

    /**
     * @return array<int, string>
     */
    public function existingColumns(string $table): array
    {
        return $this->connection()->getSchemaBuilder()->getColumnListing($table);
    }

    public function createStatement(ReportDefinitionInterface $definition, string $table): string
    {
        $lines = [sprintf('`%s` BIGINT UNSIGNED NOT NULL', $definition->primaryKey())];
        $lines[] = sprintf('`%s` INT NOT NULL', self::TENANT_COLUMN);

        foreach ($this->definitionColumns($definition) as $column) {
            $lines[] = $column->definition();
        }

        $lines[] = '`refreshed_at` DATETIME NOT NULL';
        $lines[] = sprintf('PRIMARY KEY (`%s`)', $definition->primaryKey());

        foreach ($this->indexDefinitions($definition) as $index) {
            $lines[] = $index;
        }

        return sprintf(
            "CREATE TABLE `%s` (\n  %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
            $table,
            implode(",\n  ", $lines)
        );
    }

    /**
     * Computes the statements without running any of them — `sync()` is what applies them.
     *
     * This method used to execute as a side effect of being called, which made the command's
     * `--dry-run` apply the very changes it claimed to be previewing.
     *
     * @return array<int, string>
     */
    public function alterStatements(
        ReportDefinitionInterface $definition,
        string $table,
        bool $prune = false,
        bool $allowNarrowing = false
    ): array {
        $existing = $this->existingColumns($table);
        $existingTypes = $this->existingColumnTypes($table);
        $statements = [];

        foreach ($this->definitionColumns($definition) as $column) {
            if (! in_array($column->name, $existing, true)) {
                // MySQL 8 does ADD COLUMN instantly for this shape, so adding a filter is
                // seconds even on a large table.
                $statements[] = sprintf('ALTER TABLE `%s` ADD COLUMN %s', $table, $column->definition());

                continue;
            }

            $current = $existingTypes[$column->name] ?? null;

            if ($current === null || $this->sameType($current, $column->type)) {
                continue;
            }

            // Narrowing truncates silently — a varchar(255) column cut to varchar(64) loses the
            // tail of every longer value with no error. Widening is the normal case (a longer
            // name field, more decimal precision) and is applied without ceremony.
            if (! $this->isWidening($current, $column->type) && ! $allowNarrowing) {
                $this->skipped[] = sprintf(
                    '%s.%s: %s -> %s would narrow the column; re-run with --allow-narrowing to apply',
                    $table,
                    $column->name,
                    $current,
                    $column->type
                );

                continue;
            }

            $statements[] = sprintf('ALTER TABLE `%s` MODIFY COLUMN %s', $table, $column->definition());
        }

        // A column added by ALTER used to arrive without its index, because indexes were only
        // ever emitted by CREATE TABLE. `indexed: true` then meant an index on a fresh install
        // and no index on every existing one — the filter still works, so nothing fails; it just
        // table-scans, which is the one thing this whole layer exists to avoid.
        $existingIndexes = $this->existingIndexes($table);

        foreach ($this->indexDefinitions($definition) as $name => $index) {
            if (in_array($name, $existingIndexes, true)) {
                continue;
            }

            $statements[] = sprintf('ALTER TABLE `%s` ADD %s', $table, $index);
        }

        if ($prune) {
            $declared = array_map(fn (ReportColumn $c) => $c->name, $this->definitionColumns($definition));
            $keep = [...$declared, $definition->primaryKey(), self::TENANT_COLUMN, 'refreshed_at'];

            foreach (array_diff($existing, $keep) as $orphan) {
                $statements[] = sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $orphan);
            }
        }

        return $statements;
    }

    /**
     * @return array<int, ReportColumn>
     */
    /**
     * @return array<int, string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * [column name => the type MySQL reports], e.g. `varchar(64)`, `int`, `decimal(12,2)`.
     *
     * @return array<string, string>
     */
    public function existingColumnTypes(string $table): array
    {
        $rows = $this->connection()->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        $types = [];

        foreach ($rows as $row) {
            $types[(string) $row->COLUMN_NAME] = strtolower((string) $row->COLUMN_TYPE);
        }

        return $types;
    }

    protected function sameType(string $current, string $declared): bool
    {
        return $this->normaliseType($current) === $this->normaliseType($declared);
    }

    /**
     * Is $declared strictly roomier than $current?
     *
     * Only the cases that are provably safe return true. Anything unrecognised — a type family
     * change, an enum, a json swap — is treated as narrowing and needs the explicit flag, on the
     * grounds that a wrong guess here silently destroys data.
     */
    protected function isWidening(string $current, string $declared): bool
    {
        $from = $this->normaliseType($current);
        $to = $this->normaliseType($declared);

        if (preg_match('/^varchar\((\d+)\)$/', $from, $a) && preg_match('/^varchar\((\d+)\)$/', $to, $b)) {
            return (int) $b[1] > (int) $a[1];
        }

        if (preg_match('/^decimal\((\d+),(\d+)\)$/', $from, $a)
            && preg_match('/^decimal\((\d+),(\d+)\)$/', $to, $b)
        ) {
            return (int) $b[1] >= (int) $a[1] && (int) $b[2] >= (int) $a[2] && $a !== $b;
        }

        // varchar -> text/json always holds more; the reverse never safely does.
        if (str_starts_with($from, 'varchar') && in_array($to, ['text', 'longtext', 'json'], true)) {
            return true;
        }

        $intRank = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'bigint' => 5];
        $fromInt = preg_replace('/\(.*/', '', $from);
        $toInt = preg_replace('/\(.*/', '', $to);

        if (isset($intRank[$fromInt], $intRank[$toInt])) {
            return $intRank[$toInt] > $intRank[$fromInt];
        }

        return false;
    }

    /**
     * MySQL reports `int` where a definition may say `int(11)`, and appends `unsigned`; compare
     * on the shape both agree about.
     */
    protected function normaliseType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = str_replace([' unsigned', ' zerofill', ' '], '', $type);

        return $type === 'int(11)' ? 'int' : $type;
    }

    protected function definitionColumns(ReportDefinitionInterface $definition): array
    {
        return array_values(array_filter(
            $definition->columns(),
            fn (ReportColumn $c) => $c->name !== $definition->primaryKey() && $c->name !== self::TENANT_COLUMN
        ));
    }

    /**
     * Every index leads with the tenant column, except the multi-valued ones — MySQL does not
     * allow a multi-valued index to be composite.
     *
     * Keyed by index name so `alterStatements()` can tell which ones a live table is missing.
     *
     * @return array<string, string>
     */
    protected function indexDefinitions(ReportDefinitionInterface $definition): array
    {
        $indexes = [];

        foreach ($this->definitionColumns($definition) as $column) {
            if (! $column->indexed) {
                continue;
            }

            $name = 'idx_' . $column->name;

            if ($column->multiValued) {
                $indexes[$name] = sprintf(
                    'KEY `%s` ((CAST(`%s` AS %s ARRAY)))',
                    $name,
                    $column->name,
                    $column->indexCast ?? 'CHAR(64)'
                );

                continue;
            }

            $indexes[$name] = sprintf(
                'KEY `%s` (`%s`, `%s`)',
                $name,
                self::TENANT_COLUMN,
                $column->name
            );
        }

        return $indexes;
    }

    /**
     * @return array<int, string>
     */
    protected function existingIndexes(string $table): array
    {
        return array_values(array_unique(array_map(
            fn (object $row): string => $row->Key_name,
            $this->connection()->select(sprintf('SHOW INDEX FROM `%s`', $table))
        )));
    }
}
