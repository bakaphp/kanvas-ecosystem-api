<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Tests\TestCase;

/**
 * The ALTER path against a real table.
 *
 * `createStatement()` is covered by a pure unit test, but everything that only goes wrong on an
 * *existing* table needs a live schema: the statements are diffed against what MySQL reports.
 *
 * The reporting connection is in no test's transact list, so the table is dropped explicitly.
 */
class ReportSchemaAlterTest extends TestCase
{
    private const string TABLE = 'rpt_schemaaltertest_app0';

    private ReportSchemaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReportSchemaService();
        $this->dropTable();
    }

    protected function tearDown(): void
    {
        $this->dropTable();

        parent::tearDown();
    }

    /**
     * A column declared `indexed: true` used to get its index only from CREATE TABLE, so adding
     * one to a live table left it unindexed. Nothing fails when that happens — the filter still
     * returns the right rows, it just table-scans, which is the single thing this layer exists
     * to prevent.
     */
    public function test_a_column_added_to_a_live_table_brings_its_index_with_it(): void
    {
        $this->createWith([ReportColumn::string('nivel', 64, 'Nivel', indexed: true)]);

        $this->assertNotContains('idx_estatus', $this->indexNames());

        $widened = $this->definition([
            ReportColumn::string('nivel', 64, 'Nivel', indexed: true),
            ReportColumn::string('estatus', 64, 'Estatus', indexed: true),
        ]);

        $this->service->sync($widened, 0);

        $this->assertContains('idx_estatus', $this->indexNames());
        $this->assertSame(
            ['companies_id', 'estatus'],
            $this->indexColumns('idx_estatus'),
            'every ordinary index leads with the tenant column'
        );
    }

    /**
     * `alterStatements()` executed as a side effect of being called, so the command's
     * `--dry-run` applied the very changes it printed as a preview.
     */
    public function test_computing_the_statements_does_not_apply_them(): void
    {
        $this->createWith([ReportColumn::string('nivel', 64, 'Nivel', indexed: true)]);

        $widened = $this->definition([
            ReportColumn::string('nivel', 64, 'Nivel', indexed: true),
            ReportColumn::string('estatus', 64, 'Estatus', indexed: true),
        ]);

        $statements = $this->service->alterStatements($widened, self::TABLE);

        $this->assertNotEmpty($statements, 'the preview must actually have something to show');
        $this->assertNotContains('estatus', $this->columnNames(), 'a preview must not write');
        $this->assertNotContains('idx_estatus', $this->indexNames());

        // And the same statements applied for real do land.
        $this->service->sync($widened, 0);

        $this->assertContains('estatus', $this->columnNames());
    }

    public function test_an_unchanged_definition_produces_no_statements(): void
    {
        $columns = [ReportColumn::string('nivel', 64, 'Nivel', indexed: true)];
        $this->createWith($columns);

        $this->assertSame([], $this->service->alterStatements($this->definition($columns), self::TABLE));
    }

    /**
     * @param array<int, ReportColumn> $columns
     */
    private function createWith(array $columns): void
    {
        $this->service->sync($this->definition($columns), 0);
    }

    /**
     * @param array<int, ReportColumn> $columns
     */
    private function definition(array $columns): ReportDefinitionInterface
    {
        return new class ($columns) implements ReportDefinitionInterface {
            /** @param array<int, ReportColumn> $columns */
            public function __construct(private readonly array $columns)
            {
            }

            public function model(): string
            {
                return 'schemaaltertest';
            }

            public function label(): string
            {
                return 'Schema Alter Test';
            }

            public function grain(): ReportGrainEnum
            {
                return ReportGrainEnum::PERSON;
            }

            public function primaryKey(): string
            {
                return 'demo_id';
            }

            public function columns(): array
            {
                return $this->columns;
            }
        };
    }

    /** @return array<int, string> */
    private function columnNames(): array
    {
        return $this->service->connection()->getSchemaBuilder()->getColumnListing(self::TABLE);
    }

    /** @return array<int, string> */
    private function indexNames(): array
    {
        return array_values(array_unique(array_map(
            fn (object $row): string => $row->Key_name,
            $this->service->connection()->select('SHOW INDEX FROM `' . self::TABLE . '`')
        )));
    }

    /** @return array<int, string> */
    private function indexColumns(string $index): array
    {
        $rows = $this->service->connection()->select(
            'SHOW INDEX FROM `' . self::TABLE . '` WHERE Key_name = ?',
            [$index]
        );

        usort($rows, fn (object $a, object $b): int => $a->Seq_in_index <=> $b->Seq_in_index);

        return array_map(fn (object $row): string => $row->Column_name, $rows);
    }

    private function dropTable(): void
    {
        $this->service->connection()->statement('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }
}
