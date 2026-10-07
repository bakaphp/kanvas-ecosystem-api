<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Carbon\Carbon;
use Kanvas\Analytics\Reporting\Support\ReportRefreshSuppressor;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Neuron\Tools\CommunicationStrategyTool;
use Kanvas\Connectors\Intras\Neuron\Tools\EventTrackingTool;
use Kanvas\Connectors\Intras\Neuron\Tools\ScorecardTool;
use Kanvas\Connectors\Intras\Reporting\EjecutivoDefinition;
use Kanvas\Connectors\Intras\Reporting\EmpresaPlanDefinition;
use Kanvas\Connectors\Intras\Reporting\EventoVersionDefinition;
use Kanvas\Connectors\Intras\Reporting\InscripcionDefinition;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Intelligence\Agents\Neuron\Tools\Reporting\RunReportTool;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Integration\Intras\Concerns\SeedsIntrasFlatTables;
use Tests\TestCase;
use Tests\Traits\BuildsEventVersionFixtures;

/**
 * The agent's reporting tools against real flat tables and real event versions — the questions
 * the reports screen answers, asked the way the Intras agent asks them.
 */
#[Group('serial')]
class IntrasAgentReportToolsTest extends TestCase
{
    use BuildsEventVersionFixtures {
        createEventVersionWithParticipants as buildEventVersion;
        addParticipants as registerParticipants;
    }
    use SeedsIntrasFlatTables;

    private const string MARKER = 'AGENT-TOOLS-TEST';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableIntrasReporting();
    }

    protected function tearDown(): void
    {
        $this->clearSeededFlatRows();
        $this->disableIntrasReporting();

        parent::tearDown();
    }

    /**
     * Prompts written against the count-only tool keep working: same keys, biggest group first.
     */
    public function test_run_report_keeps_its_count_breakdown_shape(): void
    {
        $this->seedRegistrations();

        $result = $this->runReport(group_by: 'tipo', distinct_column: 'peoples_id');

        $this->assertSame(['tipo'], $result['group_by']);
        $this->assertSame(
            [
                ['tipo' => 'Seminario', 'total' => 3, 'distinct_total' => 2],
                ['tipo' => 'Taller', 'total' => 1, 'distinct_total' => 1],
            ],
            $result['breakdown'],
        );
    }

    public function test_run_report_sums_revenue_per_month_in_time_order(): void
    {
        $this->seedRegistrations();

        $result = $this->runReport(
            group_by: 'fecha_inicio:month',
            aggregates: [['function' => 'SUM', 'column' => 'precio', 'alias' => 'ingreso']],
        );

        $this->assertEquals(
            [
                ['fecha_inicio_month' => '2026-02', 'ingreso' => 350],
                ['fecha_inicio_month' => '2026-03', 'ingreso' => 500],
            ],
            $result['breakdown'],
        );
        $this->assertIsNotString($result['breakdown'][0]['ingreso']);
    }

    public function test_run_report_groups_by_two_columns(): void
    {
        $this->seedRegistrations();

        $result = $this->runReport(
            group_by: 'tipo, peoples_id',
            aggregates: [['function' => 'COUNT', 'alias' => 'n']],
            order_by: 'n',
        );

        $this->assertEquals(['tipo' => 'Seminario', 'peoples_id' => 8823, 'n' => 2], $result['breakdown'][0]);
        $this->assertCount(3, $result['breakdown']);
    }

    public function test_run_report_rows_can_be_narrowed_and_sorted(): void
    {
        $this->seedRegistrations();

        $result = $this->runReport(select: 'pa_code, fecha_inicio', order_by: 'fecha_inicio', descending: true);

        $this->assertSame(['pa_code', 'fecha_inicio'], array_keys($result['rows'][0]));
        $this->assertSame(
            ['2026-03-05', '2026-03-05', '2026-02-20', '2026-02-14'],
            array_column($result['rows'], 'fecha_inicio'),
        );
    }

    /**
     * Two past versions average 24 participants and 4.9 satisfaction: 85%, an A. The cancelled
     * version, the future one and the soft-interest registrations would each move it if counted.
     */
    public function test_the_event_scorecard_averages_past_runs_and_ignores_cancelled_ones(): void
    {
        $eventoId = 990077;

        $this->seedFlatRows(new EventoVersionDefinition(), $this->rowsFrom($this->versionRow(...), [
            [992001, $eventoId, Carbon::now()->subMonths(9), 'Realizado', 4.95],
            [992002, $eventoId, Carbon::now()->subMonths(3), 'Realizado', 4.85],
            [992003, $eventoId, Carbon::now()->subMonths(6), 'Cancelado', 1.00],
            [992004, $eventoId, Carbon::now()->addMonths(2), 'Abierto', null],
        ]));

        $rows = [];
        $next = 992100;

        // [version, date, type, how many people, version status]
        foreach ([
            [992001, Carbon::now()->subMonths(9), 'CONFIRMADO', 26, 'Realizado'],
            [992001, Carbon::now()->subMonths(9), 'INTERESADO EVENTO', 3, 'Realizado'],
            [992002, Carbon::now()->subMonths(3), 'CONFIRMADO PLAN', 22, 'Realizado'],
            [992003, Carbon::now()->subMonths(6), 'CONFIRMADO', 50, 'Cancelado'],
            [992004, Carbon::now()->addMonths(2), 'CONFIRMADO', 40, 'Abierto'],
        ] as [$versionId, $date, $type, $people, $status]) {
            for ($i = 0; $i < $people; $i++, $next++) {
                $rows[] = $this->registrationRow(
                    id: $next,
                    peopleId: 995000 + ($next % 900),
                    eventoId: $eventoId,
                    versionId: $versionId,
                    date: $date,
                    type: $type,
                    versionStatus: $status,
                );
            }
        }

        $this->seedFlatRows(new InscripcionDefinition(), $rows);

        $result = new ScorecardTool()->withContext(...$this->context())('evento', id: $eventoId);
        $card = $result['tarjetas']['clasificacion'];

        $this->assertSame('success', $result['status']);
        $this->assertSame(24.0, $card['breakdown']['promedio_participantes']['measurement']);
        $this->assertSame(4.9, $card['breakdown']['satisfaccion']['measurement']);
        $this->assertSame(85.0, $card['percentage']);
        $this->assertSame('A', $card['letter']);
    }

    public function test_the_event_scorecard_resolves_an_event_by_name(): void
    {
        $this->seedFlatRows(new EventoVersionDefinition(), $this->rowsFrom($this->versionRow(...), [
            [992011, 990078, Carbon::now()->subMonths(1), 'Realizado', 4.5],
            [992012, 990078, Carbon::now()->subMonths(13), 'Realizado', 4.5],
        ]));

        $result = new ScorecardTool()->withContext(...$this->context())('evento', nombre: self::MARKER);

        $this->assertSame('success', $result['status']);
        $this->assertSame(990078, $result['identidad']['evento_id']);
        $this->assertSame(2, $result['identidad']['versiones']);
    }

    /**
     * The Plan A list: alumni of the same seminar in the window, active, in the city, at an AXIS
     * company, minus whoever already registered. Each excluded person fails exactly one rule.
     */
    public function test_the_invite_list_applies_every_rule_of_the_strategy(): void
    {
        $version = $this->createEventVersionWithParticipants(eventDate: Carbon::now()->addWeeks(3));
        $eventoId = (int) $version->event_id;
        $pastVersion = 993900;
        $axis = 988001;
        $noPlan = 988002;

        // [id, person, event, version, date, registration type]
        $this->seedFlatRows(new InscripcionDefinition(), $this->rowsFrom($this->registrationRow(...), [
            [993001, 995001, $eventoId, $pastVersion, Carbon::now()->subMonths(6), 'CONFIRMADO'],
            [993002, 995002, $eventoId, $pastVersion, Carbon::now()->subMonths(3), 'PROGRAMA'],
            [993003, 995003, $eventoId, $pastVersion, Carbon::now()->subMonths(1), 'CORTESÍA'],
            [993004, 995004, $eventoId, $pastVersion, Carbon::now()->subMonths(2), 'CANCELADO'],
            [993005, 995005, $eventoId, $pastVersion, Carbon::now()->subMonths(30), 'CONFIRMADO'],
            [993006, 995006, $eventoId, $pastVersion, Carbon::now()->subMonths(2), 'INTERCAMBIO'],
            [993007, 995007, $eventoId, $pastVersion, Carbon::now()->subMonths(2), 'CONFIRMADO'],
            [993009, 995009, $eventoId, $pastVersion, Carbon::now()->subMonths(2), 'CONFIRMADO'],
            [993010, 995002, $eventoId, $version->getId(), Carbon::now()->addWeeks(3), 'CONFIRMADO'],
        ]));

        // [person, status, city, company]
        $this->seedFlatRows(new EjecutivoDefinition(), $this->rowsFrom($this->personRow(...), [
            [995001, 'ACTIVO', 'SANTO DOMINGO', $axis],
            [995002, 'ACTIVO', 'SANTO DOMINGO', $axis],
            [995003, 'ACTIVO EMAIL', 'SANTO DOMINGO', $axis],
            [995004, 'ACTIVO', 'SANTO DOMINGO', $axis],
            [995005, 'ACTIVO', 'SANTO DOMINGO', $axis],
            [995006, 'ACTIVO', 'SANTIAGO', $axis],
            [995007, 'INACTIVO', 'SANTO DOMINGO', $axis],
            [995009, 'ACTIVO', 'SANTO DOMINGO', $noPlan],
        ]));

        $this->seedFlatRows(new EmpresaPlanDefinition(), [
            ['plan_row_id' => 994001, 'organizations_id' => $axis, 'plan' => 'AXIS ' . self::MARKER, 'fecha_expiracion' => Carbon::now()->addMonths(6)->toDateString()],
            ['plan_row_id' => 994002, 'organizations_id' => $noPlan, 'plan' => 'AXIS ' . self::MARKER, 'fecha_expiracion' => Carbon::now()->subMonth()->toDateString()],
        ]);

        $tool = new CommunicationStrategyTool()->withContext(...$this->context());

        $strategy = $tool('audiencia', version_id: $version->getId(), ciudad: 'SANTO DOMINGO');

        $this->assertSame([995001, 995003], array_map('intval', array_column($strategy['contactos'], 'peoples_id')));
        $this->assertSame(6, $strategy['exparticipantes']);
        $this->assertSame(1, $strategy['ya_inscritos_excluidos']);

        $widened = $tool('audiencia', version_id: $version->getId(), solo_axis: false);

        $this->assertSame(
            [995001, 995003, 995006, 995009],
            array_map('intval', array_column($widened['contactos'], 'peoples_id')),
        );
    }

    public function test_an_unknown_version_is_an_answer_not_a_crash(): void
    {
        $result = new CommunicationStrategyTool()->withContext(...$this->context())('audiencia', version_id: 999999999);

        $this->assertStringContainsString('No event version #999999999', $result['error']);
    }

    /**
     * 3 firm seats 8 days out is under both thresholds. The in-house version on the same dates is
     * not an open seminar and must not be listed.
     */
    public function test_plan_b_and_c_flag_open_seminars_below_their_thresholds(): void
    {
        $confirmed = $this->participantType('CONFIRMADO');
        $seminar = $this->createEventVersionWithParticipants(eventDate: Carbon::now()->addDays(8));
        $this->addParticipants($seminar, 3, $confirmed);
        $inHouse = $this->createEventVersionWithParticipants(eventDate: Carbon::now()->addDays(6));

        $versions = $this->rowsFrom($this->versionRow(...), [
            [$seminar->getId(), 990079, Carbon::now()->addDays(8), 'Abierto', null],
            [$inHouse->getId(), 990080, Carbon::now()->addDays(6), 'Abierto', null],
        ]);

        $this->seedFlatRows(new EventoVersionDefinition(), [
            [...$versions[0], 'tipo' => 'ABIERTO', 'clase' => 'SEMINARIO'],
            [...$versions[1], 'tipo' => 'IN-HOUSE', 'clase' => 'SEMINARIO'],
        ]);

        $result = new CommunicationStrategyTool()->withContext(...$this->context())('planes');
        $listed = array_column($result['seminarios'], null, 'version_id');

        $this->assertArrayNotHasKey($inHouse->getId(), $listed);
        $this->assertSame(3, $listed[$seminar->getId()]['inscritos_firmes']);
        $this->assertTrue($listed[$seminar->getId()]['plan_b']);
        $this->assertTrue($listed[$seminar->getId()]['plan_c']);
    }

    /**
     * One earlier run with 3 confirmed two weeks out, and a cancelled one with 10 that must not
     * count: the history for week 2 is exactly the earlier run's.
     */
    public function test_the_historical_curve_averages_earlier_runs_under_intras_rules(): void
    {
        $confirmed = $this->participantType('CONFIRMADO');

        $current = $this->createEventVersionWithParticipants(eventDate: Carbon::now()->addDays(20));
        $this->addParticipants($current, 2, $confirmed);

        $past = $this->sameEventVersion($current, Carbon::now()->subDays(40));
        $this->addParticipants($past, 3, $confirmed, Carbon::now()->subDays(50));

        $cancelled = $this->sameEventVersion($current, Carbon::now()->subDays(70));
        $cancelled->event_status_id = $this->cancelledStatus()->getId();
        $cancelled->saveQuietly();
        $this->addParticipants($cancelled, 10, $confirmed, Carbon::now()->subDays(80));

        $result = new EventTrackingTool()->withContext(...$this->context())('historico', version_id: $current->getId(), acumulado: false);
        $weeks = array_column($result['semanas'], null, 'semanas_antes');

        $this->assertSame(1, $result['versiones_anteriores']);
        $this->assertSame(3.0, $weeks[2]['historico_ponderado']);
        $this->assertSame(['confirmado' => 3.0], $weeks[2]['historico_por_tipo']);
        $this->assertSame(2, $weeks[3]['inscritos_ponderado']);
    }

    /**
     * Built with report refresh off: the observers would otherwise copy these registrations into
     * the shared flat tables, where nothing removes them and every later count in the suite drifts.
     */
    protected function createEventVersionWithParticipants(
        int $maxCapacity = 50,
        ?Carbon $eventDate = null,
        int $participantCount = 0,
    ): EventVersion {
        return ReportRefreshSuppressor::while(
            fn () => $this->buildEventVersion($maxCapacity, $eventDate, $participantCount)
        );
    }

    protected function addParticipants(
        EventVersion $eventVersion,
        int $count,
        ?ParticipantType $type = null,
        ?Carbon $registeredAt = null
    ): void {
        ReportRefreshSuppressor::while(
            fn () => $this->registerParticipants($eventVersion, $count, $type, $registeredAt)
        );
    }

    /**
     * @param array<int, array<string, mixed>>|null $aggregates
     *
     * @return array<string, mixed>
     */
    private function runReport(
        ?string $group_by = null,
        ?string $distinct_column = null,
        ?array $aggregates = null,
        ?string $order_by = null,
        ?bool $descending = null,
        ?string $select = null
    ): array {
        $result = new RunReportTool()->withContext(...$this->context())(
            model: 'inscripcion',
            filters: [['column' => 'codigo_version', 'operator' => '=', 'value' => self::MARKER]],
            distinct_column: $distinct_column,
            group_by: $group_by,
            aggregates: $aggregates,
            order_by: $order_by,
            descending: $descending,
            select: $select,
        );

        $this->assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));

        return $result;
    }

    private function seedRegistrations(): void
    {
        $rows = $this->rowsFrom($this->registrationRow(...), [
            [991001, 8821, 1, 1, Carbon::parse('2026-02-14'), 'CONFIRMADO'],
            [991002, 8822, 1, 1, Carbon::parse('2026-02-20'), 'CONFIRMADO'],
            [991003, 8823, 1, 1, Carbon::parse('2026-03-05'), 'CONFIRMADO'],
            [991004, 8823, 1, 2, Carbon::parse('2026-03-05'), 'CONFIRMADO'],
        ]);
        $extras = [
            ['tipo' => 'Taller', 'precio' => 100],
            ['tipo' => 'Seminario', 'precio' => 250],
            ['tipo' => 'Seminario', 'precio' => 250],
            ['tipo' => 'Seminario', 'precio' => 250],
        ];

        $this->seedFlatRows(new InscripcionDefinition(), array_map(
            fn (array $row, array $extra) => [...$row, ...$extra],
            $rows,
            $extras
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationRow(
        int $id,
        int $peopleId,
        int $eventoId,
        int $versionId,
        Carbon $date,
        string $type,
        string $versionStatus = 'Realizado'
    ): array {
        return [
            'inscripcion_id' => $id,
            'peoples_id' => $peopleId,
            'pa_code' => (string) $peopleId,
            'evento_id' => $eventoId,
            'version_id' => $versionId,
            'codigo_version' => self::MARKER,
            'tipo_inscripcion' => $type,
            'estatus_version' => $versionStatus,
            'fecha_inicio' => $date->toDateString(),
            'fechas' => json_encode([$date->toDateString()]),
            'cupos' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionRow(
        int $versionId,
        int $eventoId,
        Carbon $date,
        string $status,
        ?float $satisfaction
    ): array {
        return [
            'version_id' => $versionId,
            'evento_id' => $eventoId,
            'evento' => self::MARKER . ' ' . $eventoId,
            'estatus' => $status,
            'fecha_inicio' => $date->toDateString(),
            'fechas' => json_encode([$date->toDateString()]),
            'satisfaccion_participantes' => $satisfaction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(
        int $peopleId,
        string $status,
        string $city,
        int $companyId
    ): array {
        return [
            'peoples_id' => $peopleId,
            'nombre_completo' => 'Participante ' . $peopleId,
            'estatus' => $status,
            'ciudad' => $city,
            'empresa_id' => $companyId,
            'esta_borrado' => 0,
        ];
    }

    /**
     * One fixture row per tuple, each tuple being the argument list for `$row`.
     *
     * @param list<list<mixed>> $tuples
     *
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(callable $row, array $tuples): array
    {
        return array_map(fn (array $tuple) => $row(...$tuple), $tuples);
    }

    private function sameEventVersion(EventVersion $sibling, Carbon $startAt): EventVersion
    {
        $version = $this->createEventVersionWithParticipants(eventDate: $startAt);
        $version->event_id = $sibling->event_id;
        // (event, slug, version) is unique, and every helper-built version is version 1.
        $version->version = (int) EventVersion::where('event_id', $sibling->event_id)->max('version') + 1;
        $version->saveQuietly();

        return $version;
    }

    private function participantType(string $name): ParticipantType
    {
        $company = auth()->user()->getCurrentCompany();

        return ParticipantType::firstOrCreate([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => $name,
        ], ['users_id' => auth()->user()->getId()]);
    }

    private function cancelledStatus(): EventStatus
    {
        return EventStatus::firstOrCreate(
            ['apps_id' => app(Apps::class)->getId(), 'name' => 'Cancelado'],
            ['companies_id' => auth()->user()->getCurrentCompany()->getId(), 'users_id' => auth()->user()->getId()]
        );
    }

    /**
     * @return array{0: Apps, 1: Companies, 2: mixed}
     */
    private function context(): array
    {
        /** @var Companies $company */
        $company = auth()->user()->getCurrentCompany();

        return [app(Apps::class), $company, auth()->user()];
    }
}
