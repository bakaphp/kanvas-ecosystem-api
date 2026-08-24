<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ExportTableTool;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\CapturingCsvExportService;
use Tests\TestCase;

final class ExportTableToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;
    private CapturingCsvExportService $csv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
        $this->csv = $this->fakeCsvUpload();
    }

    public function test_exports_a_caller_authored_table_with_its_own_columns(): void
    {
        $result = $this->tool()->__invoke(
            columns: '#,Persona,Posición,Empresa,LinkedIn,PA,Estado del contacto,Notas del equipo',
            rows: json_encode([
                ['1', 'Jorgelina Durán', 'Directora de Talento y Cultura', 'Grupo Humano', 'LinkedIn', 'PA', 'No contactado', ''],
                ['2', 'José Frank Rosario', 'Vicepresidente de Gestión Humana', 'Grupo Martí', 'LinkedIn', '', 'No contactado', ''],
            ]),
            filename: 'lideres-rrhh-rd',
        );

        $this->assertSame(2, $result['row_count']);
        $this->assertStringStartsWith('https://fake.test/lideres-rrhh-rd', $result['file_url']);
        $this->assertStringContainsString('"Notas del equipo"', $this->csv->content);
        $this->assertStringContainsString('"Jorgelina Durán"', $this->csv->content);
        $this->assertStringContainsString('"2","José Frank Rosario"', $this->csv->content);
    }

    public function test_accepts_columns_as_a_json_array_and_rows_keyed_by_column(): void
    {
        $result = $this->tool()->__invoke(
            columns: json_encode(['Persona', 'PA', 'Notas']),
            rows: json_encode([
                ['Persona' => 'Juan Monegro', 'Notas' => 'Referido'],
            ]),
        );

        $this->assertSame(1, $result['row_count']);
        $this->assertStringContainsString('"Juan Monegro","","Referido"', $this->csv->content);
    }

    public function test_pads_short_rows_but_rejects_rows_wider_than_the_columns(): void
    {
        $padded = $this->tool()->__invoke(columns: 'A,B,C', rows: json_encode([['only']]));
        $this->assertSame(1, $padded['row_count']);
        $this->assertStringContainsString('"only","",""', $this->csv->content);

        $tooWide = $this->tool()->__invoke(columns: 'A,B', rows: json_encode([['a', 'b'], ['a', 'b', 'c']]));
        $this->assertArrayHasKey('error', $tooWide);
        $this->assertStringContainsString('Row 2', $tooWide['error']);
    }

    public function test_normalizes_non_string_cells(): void
    {
        $this->tool()->__invoke(
            columns: 'Id,Activo,Tags',
            rows: json_encode([[7, true, ['a', 'b']], [8, false, null]]),
        );

        $this->assertStringContainsString('"7","true","a, b"', $this->csv->content);
        $this->assertStringContainsString('"8","false",""', $this->csv->content);
    }

    public function test_rejects_missing_columns_or_rows(): void
    {
        $this->assertArrayHasKey('error', $this->tool()->__invoke(columns: '   ', rows: json_encode([['a']])));
        $this->assertArrayHasKey('error', $this->tool()->__invoke(columns: 'A,B', rows: ''));
        $this->assertArrayHasKey('error', $this->tool()->__invoke(columns: 'A,B', rows: json_encode(['flat'])));
    }

    public function test_enforces_the_row_and_column_ceilings(): void
    {
        $tooManyRows = $this->tool()->__invoke(
            columns: 'A',
            rows: json_encode(array_fill(0, ExportTableTool::MAX_ROWS + 1, ['x'])),
        );
        $this->assertArrayHasKey('error', $tooManyRows);

        $tooManyColumns = $this->tool()->__invoke(
            columns: implode(',', array_map(fn (int $i): string => 'col' . $i, range(1, ExportTableTool::MAX_COLUMNS + 1))),
            rows: json_encode([['x']]),
        );
        $this->assertArrayHasKey('error', $tooManyColumns);
    }

    public function test_sanitizes_the_filename(): void
    {
        $result = $this->tool()->__invoke(
            columns: 'A',
            rows: json_encode([['x']]),
            filename: '../../etc/passwd',
        );

        $this->assertStringStartsWith('https://fake.test/etcpasswd', $result['file_url']);

        $blank = $this->tool()->__invoke(columns: 'A', rows: json_encode([['x']]), filename: '   ');
        $this->assertStringStartsWith('https://fake.test/table', $blank['file_url']);
    }

    private function tool(): ExportTableTool
    {
        return new ExportTableTool()->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function fakeCsvUpload(): CapturingCsvExportService
    {
        $fake = new CapturingCsvExportService();
        $this->instance(CsvExportService::class, $fake);

        return $fake;
    }
}
