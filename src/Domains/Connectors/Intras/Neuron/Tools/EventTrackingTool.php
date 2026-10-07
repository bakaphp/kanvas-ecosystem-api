<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Carbon\Carbon;
use Kanvas\Connectors\Intras\Reporting\IntrasGoalPolicy;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\Repositories\InscriptionsVsHistoricalRepository;
use Kanvas\Event\Reports\Repositories\InscriptionsVsObjectiveRepository;
use Kanvas\Event\Reports\Repositories\OpenEventsTrackingRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesEventVersionForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * "¿Cómo va la inscripción?" answered under INTRAS's own rules.
 *
 * The platform already answers this — `get_events_tracking` and `get_event_report` — but with
 * generic semantics that do not match either INTRAS document:
 *
 * - the total is `array_sum($counts)`, i.e. every type including CANCELADO and INTERESADO
 *   EVENTO. On LIDERANDO MÚLTIPLES GENERACIONES that is 13 of the 22 driving the badge;
 * - the objective curve is a seven-week 5/10/20/40/60/80/100 where INTRAS specifies five weeks
 *   at 25/45/75/90/100;
 * - the banding has an amber middle at 70-86%, where the spec has only rojo and verde.
 *
 * Rather than duplicate the platform's query, this consumes the per-type counts it already
 * returns and recomputes the derived figures with `IntrasGoalPolicy`. That keeps the data live —
 * the flat tables lag behind the observer fan-out, which is wrong for "how is this selling right
 * now" — and leaves `Kanvas\Event` untouched for every other tenant.
 */
#[AgentTool(name: 'Intras Event Tracking', category: 'reporting')]
class EventTrackingTool extends Tool
{
    use TrackByInputs;
    use HasKanvasContext;
    use ResolvesEventVersionForTool;

    protected string $name = 'intras_event_tracking';

    protected ?string $description = 'Cómo va la inscripción de los eventos según las reglas de INTRAS. '
        . 'mode="seguimiento": próximos eventos con inscritos, meta, % de avance y '
        . 'estado (verde/rojo). mode="version": la curva semanal de una versión contra '
        . 'su objetivo. mode="historico": la misma curva contra el promedio de las versiones '
        . 'anteriores del mismo evento, en total y por tipo de inscripción. El total es la asistencia esperada ponderada '
        . '(0.95 confirmado/plan/programa/intercambio/crédito, 0.6 reservado/interesado), '
        . 'no un conteo bruto, y la curva es la de 5 semanas 25/45/75/90/100.';

    private const array MODES = ['seguimiento', 'version', 'historico'];

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
                description: 'seguimiento | version | historico. Por defecto seguimiento.',
                required: false,
            ),
            new ToolProperty(
                name: 'version_id',
                type: PropertyType::INTEGER,
                description: 'Id de la versión. Obligatorio en mode=version y mode=historico.',
                required: false,
            ),
            new ToolProperty(
                name: 'weeks_ahead',
                type: PropertyType::INTEGER,
                description: 'Semanas hacia adelante en seguimiento. Por defecto 5.',
                required: false,
            ),
            new ToolProperty(
                name: 'acumulado',
                type: PropertyType::BOOLEAN,
                description: 'En mode=version y mode=historico: curva acumulada (por defecto) o por semana.',
                required: false,
            ),
            new ToolProperty(
                name: 'solo_en_riesgo',
                type: PropertyType::BOOLEAN,
                description: 'En seguimiento: sólo los que están por debajo de lo esperado.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Máximo de eventos. Por defecto 25.',
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
        ?int $weeks_ahead = null,
        ?bool $acumulado = null,
        ?bool $solo_en_riesgo = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('event tracking');
        }

        $mode = mb_strtolower($mode ?? 'seguimiento');

        if (! in_array($mode, self::MODES, true)) {
            return ['error' => 'mode debe ser uno de: ' . implode(', ', self::MODES) . '.'];
        }

        try {
            return match ($mode) {
                'version' => $this->forVersion($version_id, $acumulado !== false),
                'historico' => $this->historical($version_id, $acumulado !== false),
                default => $this->tracking($weeks_ahead ?? 5, $solo_en_riesgo === true, $limit ?? 25),
            };
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tracking(int $weeksAhead, bool $onlyAtRisk, int $limit): array
    {
        $rows = OpenEventsTrackingRepository::forCompany(
            $this->app,
            $this->company,
            ['weeks_ahead' => $weeksAhead]
        );

        $events = [];

        foreach ($rows as $row) {
            $counts = (array) $row->counts;
            $weighted = IntrasGoalPolicy::weightedTotal($counts);
            $weeksUntil = $row->event_date !== null
                ? max(1, (int) floor(Carbon::now()->diffInDays(Carbon::parse($row->event_date)) / 7) + 1)
                : 1;
            $expected = IntrasGoalPolicy::expectedEnrolment((int) $row->goal, $weeksUntil);
            $color = IntrasGoalPolicy::colorFor($weighted, $expected);

            if ($onlyAtRisk && $color !== 'rojo') {
                continue;
            }

            $events[] = [
                'version_id' => $row->event_version_id,
                'evento' => $row->event_name,
                'fecha' => $row->event_date,
                'semanas_restantes' => $weeksUntil,
                'inscritos_ponderado' => $weighted,
                'inscritos_bruto' => array_sum($counts),
                'por_tipo' => $counts,
                'meta' => $row->goal,
                'esperado_a_la_fecha' => $expected,
                'avance_pct' => $expected > 0 ? round(($weighted / $expected) * 100, 1) : null,
                'estado' => $color,
            ];
        }

        return [
            'eventos' => array_slice($events, 0, $limit),
            'total' => count($events),
            'reglas' => $this->rules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forVersion(?int $versionId, bool $cumulative): array
    {
        $version = $this->resolveVersion($versionId, 'version');

        if (is_array($version)) {
            return $version;
        }

        $report = InscriptionsVsObjectiveRepository::forEventVersion($version, $cumulative);
        $goal = $report->goal;
        $semanas = [];

        foreach ($report->weeks as $week) {
            $counts = (array) $week->counts;
            $weighted = IntrasGoalPolicy::weightedTotal($counts);
            $objective = IntrasGoalPolicy::expectedEnrolment($goal, $week->week, $cumulative);

            $semanas[] = [
                'semanas_antes' => $week->week,
                'por_tipo' => $counts,
                'inscritos_ponderado' => $weighted,
                'inscritos_bruto' => array_sum($counts),
                'objetivo' => $objective,
                'estado' => IntrasGoalPolicy::colorFor($weighted, $objective),
            ];
        }

        return [
            'version_id' => $version->getId(),
            'evento' => $report->event_name,
            'meta' => $goal,
            'acumulado' => $cumulative,
            'semanas' => $semanas,
            'reglas' => $this->rules(),
        ];
    }

    /**
     * REPORTE ESTADÍSTICO charts 3 and 6: this version's curve against the average of the
     * event's earlier versions, weighted and per type.
     *
     * The platform query averages raw headcounts and counts cancelled versions, so its history
     * is both inflated and diluted. This reuses only its choice of earlier versions and applies
     * INTRAS's weights to each version's own counts before averaging.
     *
     * @return array<string, mixed>
     */
    private function historical(?int $versionId, bool $cumulative): array
    {
        $version = $this->resolveVersion($versionId, 'historico');

        if (is_array($version)) {
            return $version;
        }

        $past = InscriptionsVsHistoricalRepository::getPastVersions($version)
            ->filter(fn (EventVersion $past) => $past->eventStatus?->name !== IntrasGoalPolicy::CANCELLED_STATUS)
            ->values();

        $weightedSums = [];
        $typeSums = [];

        foreach ($past as $pastVersion) {
            foreach (InscriptionsVsObjectiveRepository::forEventVersion($pastVersion, $cumulative)->weeks as $week) {
                $counts = (array) $week->counts;
                $weightedSums[$week->week] = ($weightedSums[$week->week] ?? 0) + IntrasGoalPolicy::weightedTotal($counts);

                foreach ($counts as $slug => $count) {
                    $typeSums[$week->week][$slug] = ($typeSums[$week->week][$slug] ?? 0) + $count;
                }
            }
        }

        $versions = $past->count();
        $current = InscriptionsVsObjectiveRepository::forEventVersion($version, $cumulative);
        $semanas = [];

        foreach ($current->weeks as $week) {
            $counts = (array) $week->counts;

            $semanas[] = [
                'semanas_antes' => $week->week,
                'inscritos_ponderado' => IntrasGoalPolicy::weightedTotal($counts),
                'por_tipo' => $counts,
                'historico_ponderado' => $versions > 0 ? round(($weightedSums[$week->week] ?? 0) / $versions, 1) : null,
                'historico_por_tipo' => $versions > 0
                    ? array_map(fn (int $sum) => round($sum / $versions, 1), $typeSums[$week->week] ?? [])
                    : null,
            ];
        }

        return [
            'version_id' => $version->getId(),
            'evento' => $current->event_name,
            'acumulado' => $cumulative,
            'versiones_anteriores' => $versions,
            'semanas' => $semanas,
            'nota' => $versions === 0 ? 'Este evento no tiene versiones anteriores realizadas: no hay histórico con qué comparar.' : null,
            'reglas' => $this->rules(),
        ];
    }

    /**
     * @return EventVersion|array<string, string>
     */
    private function resolveVersion(?int $versionId, string $mode): EventVersion|array
    {
        if ($versionId === null) {
            return ['error' => sprintf('mode=%s necesita version_id.', $mode)];
        }

        return $this->resolveEventVersionOrError($versionId);
    }

    /**
     * Stated with every answer, because the number differs from what the CRM shows today and a
     * reader needs to know on what basis.
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'total' => 'asistencia esperada ponderada: 0.95 × (confirmado, confirmado plan, programa, intercambio, crédito) + 0.6 × (reservado, interesado). Otros tipos no cuentan.',
            'curva' => '5 semanas — 25 / 45 / 75 / 90 / 100 % de la meta',
            'estado' => 'verde si alcanza lo esperado, rojo si no',
            'nota' => 'la pantalla del CRM usa el conteo bruto y la curva genérica de 7 semanas, por lo que sus cifras difieren',
        ];
    }
}
