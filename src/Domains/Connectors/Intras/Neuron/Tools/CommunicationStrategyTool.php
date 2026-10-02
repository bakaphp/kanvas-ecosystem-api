<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Baka\Support\Str;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Connectors\Intras\Reporting\IntrasGoalPolicy;
use Kanvas\Event\Reports\Repositories\OpenEventsTrackingRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesEventVersionForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * ESTRATEGIAS DE COMUNICACIÓN for open seminars: who to invite, and which seminars need Plan B or C.
 *
 * The invite list is a cross-table question — past participants come from `inscripcion`, status
 * and city from `ejecutivo`, AXIS membership from `empresa_plan` — that `run_report` cannot join.
 * Each step resolves ids on one table and filters the next by them, which keeps every query inside
 * the company predicate the service injects.
 *
 * It only reads. Sending the campaign is a separate, human-approved step.
 */
#[AgentTool(name: 'Intras Communication Strategy', category: 'reporting')]
class CommunicationStrategyTool extends Tool implements HasRunKey
{
    use TrackByInputs;
    use HasKanvasContext;
    use ResolvesEventVersionForTool;

    private const array MODES = ['audiencia', 'planes'];

    /** "Exparticipantes con los tipos de inscripción: Confirmado / Confirmado Plan / Intercambio / Cortesía / Programa". */
    private const array PAST_PARTICIPANT_TYPES = ['CONFIRMADO', 'CONFIRMADO PLAN', 'INTERCAMBIO', 'CORTESÍA', 'PROGRAMA'];

    private const array DEFAULT_STATUSES = ['ACTIVO', 'ACTIVO EMAIL'];

    private const array CONTACT_COLUMNS = ['peoples_id', 'nombre_completo', 'email', 'posicion', 'empresa', 'ciudad', 'estatus'];

    public function __construct()
    {
        parent::__construct(
            name: 'intras_communication_strategy',
            description: 'Estrategias de comunicación de INTRAS para seminarios abiertos. '
                . 'mode="audiencia": lista de invitados (Plan A) para una versión — exparticipantes '
                . 'del mismo seminario en los últimos 24 meses, con estatus activo, de la ciudad y de '
                . 'empresas con plan AXIS, excluyendo a los ya inscritos. mode="planes": seminarios '
                . 'abiertos de los próximos 14 días que califican para Plan B (menos de 18 inscritos '
                . 'firmes a 2 semanas) o Plan C (menos de 15 a 10 días). Sólo consulta: no envía nada.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'mode',
                type: PropertyType::STRING,
                description: 'audiencia | planes. Por defecto audiencia.',
                required: false,
            ),
            new ToolProperty(
                name: 'version_id',
                type: PropertyType::INTEGER,
                description: 'Versión del seminario a promocionar. Obligatorio en audiencia.',
                required: false,
            ),
            new ToolProperty(
                name: 'meses',
                type: PropertyType::INTEGER,
                description: 'Antigüedad máxima de la participación, en meses. Por defecto 24.',
                required: false,
            ),
            new ToolProperty(
                name: 'ciudad',
                type: PropertyType::STRING,
                description: 'Ciudad del ejecutivo, p. ej. "SANTO DOMINGO" (la de la estrategia). Vacío = todas.',
                required: false,
            ),
            new ToolProperty(
                name: 'solo_axis',
                type: PropertyType::BOOLEAN,
                description: 'Sólo empresas con plan AXIS vigente. Por defecto true.',
                required: false,
            ),
            new ToolProperty(
                name: 'estatus',
                type: PropertyType::STRING,
                description: 'Estatus del ejecutivo, separados por coma. Por defecto "ACTIVO, ACTIVO EMAIL".',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Máximo de contactos a devolver. Por defecto 200, tope 1000.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $mode = null,
        ?int $version_id = null,
        ?int $meses = null,
        ?string $ciudad = null,
        ?bool $solo_axis = null,
        ?string $estatus = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('communication strategy');
        }

        $mode = mb_strtolower($mode ?? 'audiencia');

        if (! in_array($mode, self::MODES, true)) {
            return ['error' => 'mode debe ser uno de: ' . implode(', ', self::MODES) . '.'];
        }

        try {
            return $mode === 'planes'
                ? $this->plans()
                : $this->audience(
                    $version_id,
                    max(1, $meses ?? 24),
                    Str::trimToNull($ciudad),
                    $solo_axis !== false,
                    Str::commaList($estatus) ?: self::DEFAULT_STATUSES,
                    max(1, min($limit ?? 200, ReportQueryService::MAX_LIMIT))
                );
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param list<string> $statuses
     *
     * @return array<string, mixed>
     */
    private function audience(
        ?int $versionId,
        int $months,
        ?string $city,
        bool $onlyAxis,
        array $statuses,
        int $limit
    ): array {
        if ($versionId === null) {
            return ['error' => 'mode=audiencia necesita version_id.'];
        }

        $version = $this->resolveEventVersionOrError($versionId);

        if (is_array($version)) {
            return $version;
        }

        $today = Carbon::now()->toDateString();

        $pastParticipants = $this->peopleIds('inscripcion', [
            new ReportFilter('evento_id', '=', (int) $version->event_id),
            new ReportFilter('version_id', '!=', $versionId),
            new ReportFilter('tipo_inscripcion', 'IN', self::PAST_PARTICIPANT_TYPES),
            new ReportFilter('estatus_version', '!=', IntrasGoalPolicy::CANCELLED_STATUS),
            new ReportFilter('fecha_inicio', 'BETWEEN', [Carbon::now()->subMonths($months)->toDateString(), $today]),
        ]);

        $alreadyRegistered = $this->peopleIds('inscripcion', [
            new ReportFilter('version_id', '=', $versionId),
            new ReportFilter('tipo_inscripcion', '!=', 'CANCELADO'),
        ]);

        $candidates = array_values(array_diff($pastParticipants, $alreadyRegistered));
        $criteria = [
            'evento_id' => (int) $version->event_id,
            'meses' => $months,
            'tipos_de_inscripcion' => self::PAST_PARTICIPANT_TYPES,
            'estatus' => $statuses,
            'ciudad' => $city,
            'solo_axis' => $onlyAxis,
        ];

        $result = [
            'version_id' => $versionId,
            'exparticipantes' => count($pastParticipants),
            'ya_inscritos_excluidos' => count(array_intersect($pastParticipants, $alreadyRegistered)),
            'criterios' => $criteria,
        ];

        if ($candidates === []) {
            return [
                ...$result,
                'total' => 0,
                'contactos' => [],
                'nota' => 'Nadie cumple los criterios: no hay exparticipantes pendientes de invitar.',
            ];
        }

        $filters = [
            new ReportFilter('peoples_id', 'IN', $candidates),
            new ReportFilter('estatus', 'IN', $statuses),
            new ReportFilter('esta_borrado', '=', 0),
        ];

        if ($city !== null) {
            $filters[] = new ReportFilter('ciudad', '=', $city);
        }

        if ($onlyAxis) {
            $axisCompanies = $this->companiesWithActivePlan($today);

            if ($axisCompanies === []) {
                return [
                    ...$result,
                    'total' => 0,
                    'contactos' => [],
                    'nota' => 'No hay empresas con plan AXIS vigente.',
                ];
            }

            $filters[] = new ReportFilter('empresa_id', 'IN', $axisCompanies);
        }

        $service = new ReportQueryService();
        $definition = new ReportRegistry()->find($this->app, 'ejecutivo');
        $total = $service->count(
            $definition,
            $this->app,
            $this->company,
            $filters
        )['rows'];

        return [
            ...$result,
            'total' => $total,
            'truncado' => $total > $limit,
            'contactos' => $service->search(
                $definition,
                $this->app,
                $this->company,
                $filters,
                select: self::CONTACT_COLUMNS,
                limit: $limit,
                orderBy: 'nombre_completo',
            ),
        ];
    }

    /**
     * Uses the live registration counts rather than the flat tables: whether a seminar needs a
     * campaign today depends on who signed up this morning, and the flat rows trail the observers.
     *
     * @return array<string, mixed>
     */
    private function plans(): array
    {
        $today = Carbon::now()->startOfDay();
        $horizon = $today->copy()->addDays(IntrasGoalPolicy::PLAN_B['days']);

        $openSeminars = new ReportQueryService()->builder(
            new ReportRegistry()->find($this->app, 'evento_version'),
            $this->app,
            $this->company,
            [
                new ReportFilter('tipo', '=', 'ABIERTO'),
                new ReportFilter('clase', '=', 'SEMINARIO'),
                new ReportFilter('estatus', '!=', IntrasGoalPolicy::CANCELLED_STATUS),
                new ReportFilter('fecha_inicio', 'BETWEEN', [$today->toDateString(), $horizon->toDateString()]),
            ]
        )->pluck('version_id')->map(fn ($id) => (int) $id)->all();

        $seminars = [];

        foreach (OpenEventsTrackingRepository::forCompany($this->app, $this->company, ['weeks_ahead' => 3]) as $row) {
            if (! in_array((int) $row->event_version_id, $openSeminars, true) || $row->event_date === null) {
                continue;
            }

            $daysUntil = (int) $today->diffInDays(Carbon::parse($row->event_date)->startOfDay());
            $firm = IntrasGoalPolicy::firmCount((array) $row->counts);
            $planB = $daysUntil <= IntrasGoalPolicy::PLAN_B['days'] && $firm < IntrasGoalPolicy::PLAN_B['below'];
            $planC = $daysUntil <= IntrasGoalPolicy::PLAN_C['days'] && $firm < IntrasGoalPolicy::PLAN_C['below'];

            $seminars[] = [
                'version_id' => (int) $row->event_version_id,
                'evento' => $row->event_name,
                'fecha' => $row->event_date,
                'dias_restantes' => $daysUntil,
                'inscritos_firmes' => $firm,
                'plan_b' => $planB,
                'plan_c' => $planC,
            ];
        }

        usort($seminars, fn (array $a, array $b) => $a['dias_restantes'] <=> $b['dias_restantes']);

        return [
            'seminarios' => $seminars,
            'requieren_plan' => count(array_filter($seminars, fn (array $s) => $s['plan_b'] || $s['plan_c'])),
            'reglas' => [
                'inscritos_firmes' => implode(', ', IntrasGoalPolicy::firmTypes()),
                'plan_b' => sprintf('menos de %d inscritos firmes a %d días o menos', IntrasGoalPolicy::PLAN_B['below'], IntrasGoalPolicy::PLAN_B['days']),
                'plan_c' => sprintf('menos de %d inscritos firmes a %d días o menos', IntrasGoalPolicy::PLAN_C['below'], IntrasGoalPolicy::PLAN_C['days']),
                'nota' => 'El Plan C sólo puede repetirse una vez cada 30 días; los envíos no se registran, así que confírmalo antes de lanzarlo.',
            ],
        ];
    }

    /**
     * Distinct people on a person-grain table, without the 1,000-row cap a listing has: a popular
     * seminar's 24-month alumni can exceed it, and a truncated id list silently shrinks the audience.
     *
     * @param array<int, ReportFilter> $filters
     *
     * @return list<int>
     */
    private function peopleIds(string $model, array $filters): array
    {
        return new ReportQueryService()->builder(
            new ReportRegistry()->find($this->app, $model),
            $this->app,
            $this->company,
            $filters
        )->distinct()->pluck('peoples_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function companiesWithActivePlan(string $today): array
    {
        return new ReportQueryService()->builder(
            new ReportRegistry()->find($this->app, 'empresa_plan'),
            $this->app,
            $this->company,
        )
            ->where(
                fn (Builder $query) => $query->whereNull('fecha_expiracion')->orWhere('fecha_expiracion', '>=', $today)
            )
            ->distinct()
            ->pluck('organizations_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
