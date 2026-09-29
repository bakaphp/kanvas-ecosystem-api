<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Connectors\Intras\Reporting\Concerns\BuildsIntrasFilters;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Evaluation results, redacted for the audience that will read them.
 *
 * "EVALUACIONES INTRAS" defines four reports over the *same* answers, differing only in what is
 * withheld — and the differences are not cosmetic. The facilitator's copy deliberately **ignores**
 * the "excluir de reportes" flag that the executive's copy honours, and the internal copy shows
 * everything including opinions. Getting that backwards sends a participant's private comment to
 * the person they were commenting on.
 *
 * The 205 answers flagged "excluir de reportes" in agency 1 are the whole point of that flag, and
 * the legacy report never honoured it.
 */
#[AgentTool(name: 'Intras Evaluation Insights', category: 'reporting')]
class EvaluationInsightsTool extends Tool implements HasRunKey
{
    use TrackByInputs;
    use BuildsIntrasFilters;
    use HasKanvasContext;

    private const array MODES = ['resumen', 'texto'];

    private const array AUDIENCES = ['ejecutivos', 'facilitador', 'interno', 'tabulacion'];

    /**
     * Question groups the audience reports withhold. Matched on the question text because the
     * same question is worded per event type — "próximos seminarios" and "próximos eventos" are
     * separate rows — so a literal list would silently miss half of them.
     */
    private const array QUESTION_GROUPS = [
        'temas_sugeridos' => ['temas nos sugiere', 'temas sugiere', 'otros servicios o eventos'],
        'recomendacion' => ['recomendar', 'recomendaci'],
        'sugerencias' => ['sugerencias har', 'recomendaciones o sugerencias'],
        'opiniones' => ['opinion', 'opinión'],
    ];

    /** Which groups each audience drops, and whether it honours the exclusion flag. */
    private const array AUDIENCE_RULES = [
        'ejecutivos' => ['drop' => ['temas_sugeridos', 'recomendacion', 'sugerencias', 'opiniones'], 'honour_flag' => true],
        // Deliberate: "Incluye las preguntas aunque se les haya puesto que se excluyen del reporte."
        'facilitador' => ['drop' => ['temas_sugeridos', 'opiniones'], 'honour_flag' => false],
        'interno' => ['drop' => [], 'honour_flag' => false],
        'tabulacion' => ['drop' => ['temas_sugeridos', 'recomendacion', 'sugerencias'], 'honour_flag' => true],
    ];

    public function __construct()
    {
        parent::__construct(
            name: 'intras_evaluation_insights',
            description: 'Resultados de evaluaciones. mode="resumen": satisfacción promedio por '
                . 'pregunta. mode="texto": las respuestas abiertas para analizarlas (sugerencias '
                . 'más repetidas, temas más solicitados). audience controla qué se muestra: '
                . '"ejecutivos" (excluye preguntas abiertas y opiniones, respeta la marca de '
                . 'excluir), "facilitador" (incluye las marcadas como excluidas, sin opiniones), '
                . '"interno" (todo) o "tabulacion" (como ejecutivos pero con opiniones).',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'mode', type: PropertyType::STRING, description: 'resumen | texto. Por defecto resumen.', required: false),
            new ToolProperty(name: 'audience', type: PropertyType::STRING, description: 'ejecutivos | facilitador | interno | tabulacion. Por defecto interno.', required: false),
            new ToolProperty(name: 'version_id', type: PropertyType::INTEGER, description: 'Limitar a una versión de evento.', required: false),
            new ToolProperty(name: 'grupo', type: PropertyType::STRING, description: 'En mode=texto: temas_sugeridos | sugerencias | opiniones | recomendacion.', required: false),
            new ToolProperty(name: 'tipo', type: PropertyType::STRING, description: 'ABIERTO o IN-HOUSE.', required: false),
            new ToolProperty(name: 'clase', type: PropertyType::STRING, description: 'Clase de evento.', required: false),
            new ToolProperty(name: 'desde', type: PropertyType::STRING, description: 'Fecha del evento desde, YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'hasta', type: PropertyType::STRING, description: 'Fecha del evento hasta, YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Máximo de filas. Por defecto 50 en resumen, 200 en texto.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $mode = null,
        ?string $audience = null,
        ?int $version_id = null,
        ?string $grupo = null,
        ?string $tipo = null,
        ?string $clase = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?int $limit = null
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('evaluation insights');
        }

        $mode = mb_strtolower($mode ?? 'resumen');
        $audience = mb_strtolower($audience ?? 'interno');

        if (! in_array($mode, self::MODES, true)) {
            return ['error' => 'mode debe ser uno de: ' . implode(', ', self::MODES) . '.'];
        }

        if (! in_array($audience, self::AUDIENCES, true)) {
            return ['error' => 'audience debe ser uno de: ' . implode(', ', self::AUDIENCES) . '.'];
        }

        try {
            $rules = self::AUDIENCE_RULES[$audience];
            $filters = $this->baseFilters($version_id, $tipo, $clase, $desde, $hasta, $rules['honour_flag']);

            return $mode === 'texto'
                ? $this->text($filters, $audience, $rules, $grupo, $limit ?? 200)
                : $this->summary($filters, $audience, $rules, $limit ?? 50);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<int, ReportFilter>
     */
    private function baseFilters(
        ?int $versionId,
        ?string $tipo,
        ?string $clase,
        ?string $desde,
        ?string $hasta,
        bool $honourFlag
    ): array {
        $filters = $this->periodFilters('fecha_evento', $desde, $hasta);

        if ($versionId !== null) {
            $filters[] = new ReportFilter('version_id', '=', $versionId);
        }

        foreach (['tipo' => $tipo, 'clase' => $clase] as $column => $value) {
            if ($value !== null && $value !== '') {
                $filters[] = new ReportFilter($column, '=', mb_strtoupper($value));
            }
        }

        if ($honourFlag) {
            $filters[] = new ReportFilter('excluir_de_reportes', '=', 0);
        }

        return $filters;
    }

    /**
     * Average score per question, then the audience's withheld groups removed.
     *
     * The exclusion runs after grouping because the query surface has no NOT LIKE, and because
     * the distinct-question set is small enough that filtering it exactly beats approximating it
     * in SQL.
     *
     * @param array<int, ReportFilter> $filters
     * @param array{drop: list<string>, honour_flag: bool} $rules
     *
     * @return array<string, mixed>
     */
    private function summary(
        array $filters,
        string $audience,
        array $rules,
        int $limit
    ): array {
        $rows = new ReportQueryService()->aggregate(
            new ReportRegistry()->find($this->app, 'evaluacion'),
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [
                    ['function' => 'AVG', 'column' => 'puntuacion', 'alias' => 'promedio'],
                    ['function' => 'COUNT', 'alias' => 'respuestas'],
                ],
                groupBy: ['pregunta', 'tipo_pregunta'],
                orderBy: 'respuestas',
                limit: ReportQueryService::MAX_LIMIT
            ),
            [...$filters, new ReportFilter('es_numerica', '=', 1)]
        );

        $kept = [];

        foreach ($rows as $row) {
            if ($this->groupOf((string) $row['pregunta']) !== null
                && in_array($this->groupOf((string) $row['pregunta']), $rules['drop'], true)) {
                continue;
            }

            $row['promedio'] = round((float) $row['promedio'], 2);
            $kept[] = $row;
        }

        return [
            'audience' => $audience,
            'preguntas' => array_slice($kept, 0, $limit),
            'reglas' => $this->describeAudience($audience, $rules),
        ];
    }

    /**
     * The open answers themselves, for the questions the audience may see.
     *
     * @param array<int, ReportFilter> $filters
     * @param array{drop: list<string>, honour_flag: bool} $rules
     *
     * @return array<string, mixed>
     */
    private function text(
        array $filters,
        string $audience,
        array $rules,
        ?string $group,
        int $limit
    ): array {
        if ($group !== null && in_array($group, $rules['drop'], true)) {
            return [
                'error' => sprintf('El grupo "%s" no se incluye en el reporte de %s.', $group, $audience),
                'reglas' => $this->describeAudience($audience, $rules),
            ];
        }

        $service = new ReportQueryService();
        $definition = new ReportRegistry()->find($this->app, 'evaluacion');

        $rows = $service->search(
            $definition,
            $this->app,
            $this->company,
            [...$filters, new ReportFilter('es_numerica', '=', 0)],
            select: ['pregunta', 'tipo_pregunta', 'respuesta', 'evento', 'empresa', 'fecha_evento'],
            limit: ReportQueryService::MAX_LIMIT
        );

        $kept = [];

        foreach ($rows as $row) {
            $rowGroup = $this->groupOf((string) $row['pregunta']);

            if ($rowGroup !== null && in_array($rowGroup, $rules['drop'], true)) {
                continue;
            }

            if ($group !== null && $rowGroup !== $group) {
                continue;
            }

            foreach ($this->unwrap((string) ($row['respuesta'] ?? '')) as $answer) {
                $kept[] = [...$row, 'respuesta' => $answer, 'grupo' => $rowGroup];

                if (count($kept) >= $limit) {
                    break 2;
                }
            }
        }

        return [
            'audience' => $audience,
            'grupo' => $group,
            'respuestas' => $kept,
            'total' => count($kept),
            'nota' => 'respuestas en crudo para analizar; agrúpalas por tema tú mismo y cita cuántas veces aparece cada uno',
            'reglas' => $this->describeAudience($audience, $rules),
        ];
    }

    /**
     * One stored answer can be several answers.
     *
     * 6,025 of agency 1's 39,403 open answers are JSON arrays of `{description, question_id}`,
     * and a single row can hold three or four separate suggestions. Handing the raw JSON to a
     * caller asking "which themes repeat most" would both poison the counts with syntax and hide
     * the extra suggestions inside each row.
     *
     * @return list<string>
     */
    private function unwrap(string $stored): array
    {
        $trimmed = trim($stored);

        if ($trimmed === '') {
            return [];
        }

        if (! str_starts_with($trimmed, '[') && ! str_starts_with($trimmed, '{')) {
            return [$trimmed];
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            return [$trimmed];
        }

        $answers = [];

        foreach ($decoded as $entry) {
            $value = is_array($entry) ? ($entry['description'] ?? null) : $entry;
            $value = is_string($value) ? trim($value) : '';

            if ($value !== '') {
                $answers[] = $value;
            }
        }

        return $answers === [] ? [$trimmed] : $answers;
    }

    private function groupOf(string $question): ?string
    {
        $needle = mb_strtolower($question);

        foreach (self::QUESTION_GROUPS as $group => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($needle, $pattern)) {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * @param array{drop: list<string>, honour_flag: bool} $rules
     *
     * @return array<string, string>
     */
    private function describeAudience(string $audience, array $rules): array
    {
        return [
            'audiencia' => $audience,
            'excluye' => $rules['drop'] === [] ? 'nada' : implode(', ', $rules['drop']),
            'marca_excluir_de_reportes' => $rules['honour_flag']
                ? 'respetada'
                : 'ignorada a propósito — el reporte de facilitador e interno incluyen esas respuestas',
        ];
    }
}
