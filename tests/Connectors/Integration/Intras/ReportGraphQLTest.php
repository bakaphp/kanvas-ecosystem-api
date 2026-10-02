<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Connectors\Intras\Reporting\InscripcionDefinition;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The three report queries, end to end through Lighthouse against a real flat table.
 *
 * Serial for the same reason as `ReportFanOutTest`: enablement is an app setting in shared Redis,
 * so a sibling worker's teardown can switch the connector off mid-test.
 *
 * Rows are written under the logged-in user's own company, because that is the only company the
 * resolver will ever query — the acting context, not an argument, picks it.
 */
#[Group('serial')]
class ReportGraphQLTest extends TestCase
{
    private Apps $kanvasApp;
    private InscripcionDefinition $definition;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->definition = new InscripcionDefinition();
        $this->companyId = (int) static::$cachedUser->getCurrentCompany()->getId();

        $this->kanvasApp->set(ConfigurationEnum::INTRAS_DB_HOST->value, '127.0.0.1');
        $this->kanvasApp->set(ConfigurationEnum::INTRAS_DB_DATABASE->value, 'intras');

        new ReportSchemaService()->sync($this->definition, $this->kanvasApp->getId());

        $this->clearRows();
        $this->seedRows();
    }

    protected function tearDown(): void
    {
        $this->clearRows();
        $this->kanvasApp->del(ConfigurationEnum::INTRAS_DB_HOST->value);
        $this->kanvasApp->del(ConfigurationEnum::INTRAS_DB_DATABASE->value);

        parent::tearDown();
    }

    public function test_report_models_lists_the_tables_and_their_columns(): void
    {
        $models = $this->graphQL('
            query {
                reportModels {
                    model
                    grain
                    primary_key
                    columns { name type label multi_valued }
                }
            }
        ')->assertSuccessful()->json('data.reportModels');

        $inscripcion = collect($models)->firstWhere('model', 'inscripcion');

        $this->assertNotNull($inscripcion);
        $this->assertSame('inscripcion_id', $inscripcion['primary_key']);

        $fecha = collect($inscripcion['columns'])->firstWhere('name', 'fecha_inicio');
        $this->assertSame('date', $fecha['type']);
    }

    public function test_an_app_without_the_connector_has_no_report_models(): void
    {
        $this->kanvasApp->del(ConfigurationEnum::INTRAS_DB_HOST->value);

        $this->graphQL('query { reportModels { model } }')
            ->assertSuccessful()
            ->assertJsonPath('data.reportModels', []);
    }

    public function test_aggregate_groups_counts_and_ranks(): void
    {
        $result = $this->graphQL('
            query {
                reportAggregate(
                    model: "inscripcion"
                    filters: [{ column: "es_asistente", operator: EQ, value: 1 }]
                    group_by: ["tipo"]
                    aggregates: [
                        { function: COUNT, alias: "inscripciones" }
                        { function: COUNT_DISTINCT, column: "peoples_id", alias: "ejecutivos" }
                        { function: SUM, column: "precio", alias: "ingreso" }
                    ]
                    order_by: "inscripciones"
                ) { columns rows }
            }
        ')->assertSuccessful()->json('data.reportAggregate');

        $this->assertSame(['tipo', 'inscripciones', 'ejecutivos', 'ingreso'], $result['columns']);
        // Equals, not Same: a SUM over a decimal column comes back as 500.0, and that is the point —
        // it is a number now, where MySQL handed back the string "500.00".
        $this->assertEquals(
            [
                ['tipo' => 'Seminario', 'inscripciones' => 2, 'ejecutivos' => 2, 'ingreso' => 500],
                ['tipo' => 'Taller', 'inscripciones' => 1, 'ejecutivos' => 1, 'ingreso' => 100],
            ],
            $result['rows'],
        );
        $this->assertIsNotString($result['rows'][0]['ingreso']);
        $this->assertIsInt($result['rows'][0]['inscripciones']);
    }

    public function test_aggregate_buckets_a_date_into_a_time_series(): void
    {
        $result = $this->graphQL('
            query {
                reportAggregate(
                    model: "inscripcion"
                    filters: [{ column: "fecha_inicio", operator: BETWEEN, value: ["2026-01-01", "2026-12-31"] }]
                    group_by: ["fecha_inicio:month"]
                    aggregates: [{ function: COUNT, alias: "inscripciones" }]
                ) { columns rows }
            }
        ')->assertSuccessful()->json('data.reportAggregate');

        $this->assertSame(['fecha_inicio_month', 'inscripciones'], $result['columns']);
        $this->assertSame(
            [
                ['fecha_inicio_month' => '2026-02', 'inscripciones' => 2],
                ['fecha_inicio_month' => '2026-03', 'inscripciones' => 1],
            ],
            $result['rows'],
        );
    }

    public function test_rows_are_filtered_sorted_paged_and_totalled(): void
    {
        $query = '
            query($page: Int!) {
                reportRows(
                    model: "inscripcion"
                    filters: [{ column: "tipo", operator: IN, value: ["Seminario", "Taller"] }]
                    select: ["pa_code", "fecha_inicio"]
                    order_by: "fecha_inicio"
                    descending: true
                    first: 2
                    page: $page
                ) { total rows }
            }
        ';

        $first = $this->graphQL($query, ['page' => 1])->assertSuccessful()->json('data.reportRows');
        $second = $this->graphQL($query, ['page' => 2])->assertSuccessful()->json('data.reportRows');

        $this->assertSame(4, $first['total']);
        $this->assertSame(['2026-03-05', '2026-02-20'], array_column($first['rows'], 'fecha_inicio'));
        $this->assertSame(['2026-02-14', '2019-05-10'], array_column($second['rows'], 'fecha_inicio'));
    }

    public function test_an_undeclared_column_is_a_graphql_error_not_a_sql_one(): void
    {
        $response = $this->graphQL('
            query {
                reportAggregate(
                    model: "inscripcion"
                    group_by: ["password"]
                    aggregates: [{ function: COUNT }]
                ) { columns rows }
            }
        ');

        $this->assertStringContainsString('Unknown report column "password"', (string) $response->json('errors.0.message'));
    }

    public function test_an_unknown_model_is_a_graphql_error(): void
    {
        $response = $this->graphQL('
            query { reportRows(model: "users") { total rows } }
        ');

        $this->assertStringContainsString('Unknown report model "users"', (string) $response->json('errors.0.message'));
    }

    private function seedRows(): void
    {
        $service = new ReportSchemaService();
        $table = $service->tableFor($this->definition, $this->kanvasApp->getId());
        $now = date('Y-m-d H:i:s');

        // The last row belongs to another company in the same app. Every exact count and total
        // in this class doubles as the cross-tenant leak check: it would be off by one if it leaked.
        $rows = [
            [990001, $this->companyId, 8821, '40123', 'Seminario', '2019-05-10', 1, 250],
            [990002, $this->companyId, 8821, '40123', 'Taller', '2026-02-14', 1, 100],
            [990003, $this->companyId, 8822, '40987', 'Seminario', '2026-02-20', 1, 250],
            [990004, $this->companyId, 8823, '41002', 'Seminario', '2026-03-05', 0, 250],
            [990005, $this->otherCompanyId(), 9901, '50001', 'Seminario', '2026-02-20', 1, 9999],
        ];

        foreach ($rows as [$id, $companyId, $peopleId, $pa, $tipo, $fecha, $attended, $precio]) {
            $service->connection()->table($table)->insert([
                'inscripcion_id' => $id,
                'companies_id' => $companyId,
                'peoples_id' => $peopleId,
                'pa_code' => $pa,
                'tipo' => $tipo,
                'es_asistente' => $attended,
                'precio' => $precio,
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
            ->whereIn('companies_id', [$this->companyId, $this->otherCompanyId()])
            ->delete();
    }

    private function otherCompanyId(): int
    {
        return $this->companyId + 1;
    }
}
