<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Reporting\InscripcionDefinition;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

/**
 * The 17-condition cluster, end to end against a real flat table.
 *
 * Elasticsearch matched nested children independently, so "Seminario AND Q1 2026" returned
 * someone who attended a Seminario in 2019 and something unrelated in February — which is why
 * `participatesInEventByInscriptionType()` exists in the legacy code as a MySQL re-check. At this
 * grain it is an ordinary AND, and these tests are what hold that.
 *
 * Not using DatabaseTransactions: the reporting connection is not in any test's transact list,
 * so rows are cleaned up explicitly instead.
 */
class ReportQueryServiceTest extends TestCase
{
    private const int COMPANY = 999901;

    private Apps $kanvasApp;
    private Companies $company;
    private InscripcionDefinition $definition;
    private ReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->definition = new InscripcionDefinition();
        $this->service = new ReportQueryService();

        $this->company = new Companies();
        $this->company->id = self::COMPANY;

        new ReportSchemaService()->sync($this->definition, $this->kanvasApp->getId());

        $this->clearRows();
        $this->seedRows();
    }

    protected function tearDown(): void
    {
        $this->clearRows();
        parent::tearDown();
    }

    public function test_correlated_conditions_must_hold_on_the_same_registration(): void
    {
        $counts = $this->service->count($this->definition, $this->kanvasApp, $this->company, [
            new ReportFilter('nivel', '=', 'Ejecutivo'),
            new ReportFilter('tipo', '=', 'Seminario'),
            new ReportFilter('fecha_inicio', 'BETWEEN', ['2026-01-01', '2026-03-31']),
        ], distinctColumn: 'peoples_id');

        // Laura matches Seminario (2019) and Q1-2026 (Taller) but never together.
        $this->assertSame(1, $counts['distinct'], 'only Stephanie satisfies all three on one row');
        $this->assertSame(1, $counts['rows']);
    }

    public function test_counting_rows_and_counting_people_are_different_questions(): void
    {
        $counts = $this->service->count($this->definition, $this->kanvasApp, $this->company, [
            new ReportFilter('nivel', '=', 'Ejecutivo'),
        ], distinctColumn: 'peoples_id');

        $this->assertSame(3, $counts['rows'], 'three registrations');
        $this->assertSame(2, $counts['distinct'], 'two people');
    }

    public function test_a_date_inside_the_array_matches_without_a_second_grain(): void
    {
        $rows = $this->service->search($this->definition, $this->kanvasApp, $this->company, [
            new ReportFilter('fechas', 'MEMBER OF', '2026-02-14'),
        ], select: ['pa_code', 'tipo']);

        $this->assertCount(2, $rows);
    }

    public function test_the_breakdown_feeds_a_chart(): void
    {
        $breakdown = $this->service->breakdown(
            $this->definition,
            $this->kanvasApp,
            $this->company,
            'tipo',
            [],
            'peoples_id'
        );

        $byType = array_column($breakdown, null, 'tipo');

        $this->assertSame(2, (int) $byType['Seminario']['total']);
        $this->assertSame(1, (int) $byType['Taller']['total']);
    }

    /**
     * The company predicate is added by the service before any caller filter, and there is no
     * way to ask for a query without it.
     */
    public function test_another_company_sees_none_of_these_rows(): void
    {
        $other = new Companies();
        $other->id = self::COMPANY + 1;

        $counts = $this->service->count($this->definition, $this->kanvasApp, $other);

        $this->assertSame(0, $counts['rows']);
    }

    /**
     * Column names are checked against the definition, so agent-supplied input cannot reach a
     * column that was never declared.
     */
    public function test_an_undeclared_column_is_rejected_rather_than_reaching_sql(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->count($this->definition, $this->kanvasApp, $this->company, [
            new ReportFilter('drop_table_users', '=', 'x'),
        ]);
    }

    public function test_an_unsupported_operator_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        new ReportFilter('nivel', 'REGEXP', '.*');
    }

    private function seedRows(): void
    {
        $table = new ReportSchemaService()->tableFor($this->definition, $this->kanvasApp->getId());
        $now = date('Y-m-d H:i:s');

        $rows = [
            [1, 8821, '40123', 'Laura Hache', 'Seminario', '2019-05-10'],
            [2, 8821, '40123', 'Laura Hache', 'Taller', '2026-02-14'],
            [3, 8822, '40987', 'Stephanie V', 'Seminario', '2026-02-14'],
        ];

        foreach ($rows as [$id, $peopleId, $pa, $name, $tipo, $fecha]) {
            new ReportSchemaService()->connection()->table($table)->insert([
                'inscripcion_id' => $id,
                'companies_id' => self::COMPANY,
                'peoples_id' => $peopleId,
                'pa_code' => $pa,
                'nombre_completo' => $name,
                'nivel' => 'Ejecutivo',
                'tipo' => $tipo,
                'es_asistente' => 1,
                'cupos' => 1,
                'fecha_inicio' => $fecha,
                'fechas' => json_encode([$fecha]),
                'refreshed_at' => $now,
            ]);
        }
    }

    private function clearRows(): void
    {
        $service = new ReportSchemaService();

        $service->connection()
            ->table($service->tableFor($this->definition, $this->kanvasApp->getId()))
            ->whereIn('companies_id', [self::COMPANY, self::COMPANY + 1])
            ->delete();
    }
}
