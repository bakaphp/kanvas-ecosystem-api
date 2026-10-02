<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Connectors\Intras\Reporting\Scoring\IntrasScorecards;
use Kanvas\Connectors\Intras\Reporting\Scoring\ScorecardMeasurementService;
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
 * The A–E letters, computed rather than looked up.
 *
 * This is the only route to them. `companies.classification` is empty or `'n'` on every row in
 * SIPGO and there is no potentiality column at all, so the letters the business talks about have
 * never actually existed as data — they live in four scoring sheets that were applied by hand.
 * A tool that read the column would return nothing and look like a data bug.
 *
 * Order matters and is not the caller's problem to remember: an ejecutivo's potencialidad scores
 * the *company's* two letters as two of its four criteria, so asking for a person's potencialidad
 * silently requires both empresa cards first. `scoreEjecutivo()` runs them.
 *
 * An evento has only the classification card; the sheets define no potencialidad for events.
 */
#[AgentTool(name: 'Intras Scorecard', category: 'reporting')]
class ScorecardTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    private const array ENTITIES = ['ejecutivo', 'empresa', 'evento', 'definicion'];

    public function __construct()
    {
        parent::__construct(
            name: 'intras_scorecard',
            description: 'Calcula la clasificación y la potencialidad (A-E) de un ejecutivo o de una '
                . 'empresa, y la clasificación de un evento, con las cinco hojas de puntuación de '
                . 'INTRAS. Estas letras NO están '
                . 'almacenadas en ningún campo: se calculan aquí a partir de la participación real, '
                . 'las cotizaciones aprobadas y los planes. Usa entidad "definicion" para explicar '
                . 'qué criterios y pesos componen cada hoja sin puntuar a nadie. Devuelve la letra, '
                . 'el porcentaje, el desglose por criterio y la cobertura.',
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
                name: 'entidad',
                type: PropertyType::STRING,
                description: 'ejecutivo | empresa | evento | definicion.',
                required: true,
                enum: self::ENTITIES,
            ),
            new ToolProperty(
                name: 'nombre',
                type: PropertyType::STRING,
                description: 'Nombre del ejecutivo, la empresa o el evento. Búsqueda parcial. Si hay varias '
                    . 'coincidencias se devuelven para que elijas, sin puntuar.',
                required: false,
            ),
            new ToolProperty(
                name: 'id',
                type: PropertyType::INTEGER,
                description: 'peoples_id del ejecutivo, organizations_id de la empresa o evento_id del evento, si ya lo tienes.',
                required: false,
            ),
            new ToolProperty(
                name: 'tarjeta',
                type: PropertyType::STRING,
                description: 'clasificacion | potencialidad | ambas. Por defecto ambas.',
                required: false,
                enum: ['clasificacion', 'potencialidad', 'ambas'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $entidad,
        ?string $nombre = null,
        ?int $id = null,
        ?string $tarjeta = null
    ): array {
        $entidad = mb_strtolower(trim($entidad));
        $tarjeta = mb_strtolower(trim($tarjeta ?? 'ambas'));

        if (! in_array($entidad, self::ENTITIES, true)) {
            return [
                'status' => 'error',
                'message' => 'entidad debe ser: ' . implode(', ', self::ENTITIES) . '.',
            ];
        }

        try {
            if ($entidad === 'definicion') {
                return $this->describeCards();
            }

            $match = $this->resolveEntity($entidad, $nombre, $id);

            if (isset($match['status'])) {
                return $match;
            }

            return match ($entidad) {
                'ejecutivo' => $this->scoreEjecutivo((int) $match['id'], $match['row'], $tarjeta),
                'evento' => $this->scoreEvento((int) $match['id'], $match['row']),
                default => $this->scoreEmpresa((int) $match['id'], $match['row'], $tarjeta),
            };
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'No se pudo calcular la puntuación: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function scoreEjecutivo(int $peopleId, array $row, string $tarjeta): array
    {
        $measurements = new ScorecardMeasurementService($this->app, $this->company);
        $cards = [];

        if ($tarjeta !== 'potencialidad') {
            $cards['clasificacion'] = IntrasScorecards::ejecutivoClasificacion()
                ->score($measurements->forEjecutivo($peopleId))
                ->toArray();
        }

        if ($tarjeta !== 'clasificacion') {
            // Two of this card's four criteria are the company's own letters, so the empresa
            // cards have to run first. A person with no company contributes null for both, which
            // `Scorecard::score()` drops from the score rather than counting as the worst band.
            $empresaId = (int) ($row['empresa_id'] ?? 0);
            $letters = $empresaId > 0 ? $this->empresaLetters($empresaId) : [];

            $cards['potencialidad'] = IntrasScorecards::ejecutivoPotencialidad()
                ->score($measurements->forEjecutivoPotential($peopleId, $letters))
                ->toArray();

            $cards['potencialidad']['depende_de'] = [
                'empresa' => $row['empresa'] ?? null,
                'empresa_id' => $empresaId > 0 ? $empresaId : null,
                'letras_empresa' => $letters,
            ];
        }

        return $this->present('ejecutivo', [
            'peoples_id' => $peopleId,
            'nombre' => $row['nombre_completo'] ?? null,
            'pa_code' => $row['pa_code'] ?? null,
            'empresa' => $row['empresa'] ?? null,
            'nivel' => $row['nivel'] ?? null,
        ], $cards);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function scoreEmpresa(int $organizationId, array $row, string $tarjeta): array
    {
        $measurements = new ScorecardMeasurementService($this->app, $this->company);
        $cards = [];

        if ($tarjeta !== 'potencialidad') {
            $cards['clasificacion'] = IntrasScorecards::empresaClasificacion()
                ->score($measurements->forEmpresa($organizationId))
                ->toArray();
        }

        if ($tarjeta !== 'clasificacion') {
            $cards['potencialidad'] = IntrasScorecards::empresaPotencialidad()
                ->score($measurements->forEmpresaPotential($organizationId))
                ->toArray();
        }

        return $this->present('empresa', [
            'organizations_id' => $organizationId,
            'nombre' => $row['nombre'] ?? null,
            'sector' => $row['sector'] ?? null,
            'tamano' => $row['tamano'] ?? null,
            'ciudad' => $row['ciudad'] ?? null,
        ], $cards);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function scoreEvento(int $eventoId, array $row): array
    {
        $measurements = new ScorecardMeasurementService($this->app, $this->company);

        return $this->present('evento', [
            'evento_id' => $eventoId,
            'nombre' => $row['evento'] ?? null,
            'versiones' => $row['versiones'] ?? null,
        ], [
            'clasificacion' => IntrasScorecards::eventoClasificacion()
                ->score($measurements->forEvento($eventoId))
                ->toArray(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function empresaLetters(int $organizationId): array
    {
        $measurements = new ScorecardMeasurementService($this->app, $this->company);

        return [
            'clasificacion' => IntrasScorecards::empresaClasificacion()
                ->score($measurements->forEmpresa($organizationId))->letter,
            'potencialidad' => IntrasScorecards::empresaPotencialidad()
                ->score($measurements->forEmpresaPotential($organizationId))->letter,
        ];
    }

    /**
     * @param array<string, mixed>               $identity
     * @param array<string, array<string, mixed>> $cards
     *
     * @return array<string, mixed>
     */
    private function present(string $entity, array $identity, array $cards): array
    {
        $partial = array_filter($cards, fn (array $card): bool => (float) $card['coverage'] < 100.0);

        return array_filter([
            'status' => 'success',
            'entidad' => $entity,
            'identidad' => array_filter($identity, fn ($value) => $value !== null),
            'tarjetas' => $cards,
            'nota' => $this->coverageNote($partial),
        ], fn ($value) => $value !== null);
    }

    /**
     * A letter computed on 89% of a card's weight is still a letter, but quoting it as if it were
     * complete is how a partial figure ends up in a slide. Say it once, in the answer.
     *
     * @param array<string, array<string, mixed>> $partial
     */
    private function coverageNote(array $partial): ?string
    {
        if ($partial === []) {
            return null;
        }

        $parts = [];

        foreach ($partial as $name => $card) {
            $parts[] = sprintf(
                '%s se calculó sobre el %s%% del peso (faltan: %s)',
                $name,
                $card['coverage'],
                implode('; ', array_map(
                    static fn (string $key, string $why): string => $key . ' — ' . $why,
                    array_keys($card['skipped']),
                    $card['skipped']
                ))
            );
        }

        return 'Cobertura parcial: ' . implode('. ', $parts) . '.';
    }

    /**
     * @return array<string, mixed>
     */
    private function describeCards(): array
    {
        $cards = [];

        foreach (IntrasScorecards::all() as $card) {
            $cards[$card->key] = [
                'label' => $card->label,
                'cobertura' => round($card->scorableWeight() * 100.0, 2),
                'letras' => $card->letters,
                'criterios' => array_map(
                    static fn ($criterion): array => array_filter([
                        'key' => $criterion->key,
                        'label' => $criterion->label,
                        'peso' => $criterion->weight,
                        'no_calculable' => $criterion->scorable ? null : $criterion->unscorableReason,
                    ], fn ($value) => $value !== null),
                    $card->criteria
                ),
            ];
        }

        return [
            'status' => 'success',
            'entidad' => 'definicion',
            'tarjetas' => $cards,
            'nota' => 'Las letras A-E no están almacenadas en ningún campo de SIPGO: se calculan con '
                . 'estas hojas a partir de la participación, las cotizaciones aprobadas y los planes.',
        ];
    }

    /**
     * @return array{id: int, row: array<string, mixed>}|array<string, mixed>
     */
    private function resolveEntity(string $entity, ?string $nombre, ?int $id): array
    {
        if ($entity === 'evento') {
            return $this->resolveEvento($nombre, $id);
        }

        $model = $entity === 'ejecutivo' ? 'ejecutivo' : 'empresa';
        $key = $entity === 'ejecutivo' ? 'peoples_id' : 'organizations_id';
        $nameColumn = $entity === 'ejecutivo' ? 'nombre_completo' : 'nombre';

        $definition = new ReportRegistry()->find($this->app, $model);
        $service = new ReportQueryService();

        if ($id !== null && $id > 0) {
            $rows = $service->search($definition, $this->app, $this->company, [new ReportFilter($key, '=', $id)], limit: 1);

            return $rows === []
                ? ['status' => 'error', 'message' => sprintf('No existe ese %s (%s %d).', $entity, $key, $id)]
                : ['id' => $id, 'row' => $rows[0]];
        }

        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            return ['status' => 'error', 'message' => 'Indica el nombre o el id del ' . $entity . '.'];
        }

        $rows = $service->search(
            $definition,
            $this->app,
            $this->company,
            [new ReportFilter($nameColumn, 'LIKE', '%' . $nombre . '%')],
            limit: 10
        );

        if ($rows === []) {
            return ['status' => 'error', 'message' => sprintf('Ningún registro de %s coincide con "%s".', $entity, $nombre)];
        }

        if (count($rows) > 1) {
            return [
                'status' => 'ambiguo',
                'message' => 'Varias coincidencias. Vuelve a llamar con el id de la que corresponda.',
                'coincidencias' => array_map(
                    static fn (array $row): array => array_filter([
                        'id' => $row['peoples_id'] ?? $row['organizations_id'] ?? null,
                        'nombre' => $row['nombre_completo'] ?? $row['nombre'] ?? null,
                        'empresa' => $row['empresa'] ?? null,
                        'sector' => $row['sector'] ?? null,
                    ], fn ($value) => $value !== null),
                    $rows
                ),
            ];
        }

        return ['id' => (int) ($rows[0][$key] ?? 0), 'row' => $rows[0]];
    }

    /**
     * Events have no table of their own — each row of `evento_version` is one run — so the event
     * is the group of its versions.
     *
     * @return array{id: int, row: array<string, mixed>}|array<string, mixed>
     */
    private function resolveEvento(?string $nombre, ?int $id): array
    {
        $nombre = trim((string) $nombre);

        if (($id === null || $id <= 0) && $nombre === '') {
            return ['status' => 'error', 'message' => 'Indica el nombre o el evento_id del evento.'];
        }

        $rows = new ReportQueryService()->aggregate(
            new ReportRegistry()->find($this->app, 'evento_version'),
            $this->app,
            $this->company,
            AggregateRequest::fromInput(
                aggregates: [['function' => 'COUNT', 'alias' => 'versiones']],
                groupBy: ['evento_id', 'evento'],
                orderBy: 'versiones',
                limit: 10
            ),
            $id !== null && $id > 0
                ? [new ReportFilter('evento_id', '=', $id)]
                : [new ReportFilter('evento', 'LIKE', '%' . $nombre . '%')]
        );

        if ($rows === []) {
            return ['status' => 'error', 'message' => $id !== null && $id > 0
                ? sprintf('No existe ese evento (evento_id %d).', $id)
                : sprintf('Ningún evento coincide con "%s".', $nombre)];
        }

        if (count($rows) > 1) {
            return [
                'status' => 'ambiguo',
                'message' => 'Varias coincidencias. Vuelve a llamar con el evento_id de la que corresponda.',
                'coincidencias' => array_map(
                    static fn (array $row): array => [
                        'id' => $row['evento_id'],
                        'nombre' => $row['evento'],
                        'versiones' => $row['versiones'],
                    ],
                    $rows
                ),
            ];
        }

        return ['id' => (int) $rows[0]['evento_id'], 'row' => $rows[0]];
    }
}
