<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Neuron\Tools;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Find an event version from what a person actually knows about it.
 *
 * Every other INTRAS tool takes a `version_id` — an internal Kanvas id nobody has ever seen. An
 * analyst has the name, the SIPGO code off a report, or roughly when it ran, and none of those
 * could be turned into that id. A real request ("HELLO WELLNESS, EV3230, 2 de septiembre") had
 * no entry point at all, and the agent burned its run budget guessing.
 *
 * Three things this has to tolerate, each of which broke that request on its own:
 *
 * - **Names are stored unspaced.** The event is `HELLOWELLNESS`, so `LIKE '%HELLO WELLNESS%'`
 *   finds nothing. Both sides are squashed to letters and digits before comparing.
 * - **`EV3230` is not stored anywhere in that form.** It is the legacy *event* id, which survives
 *   only as the suffix of a slug (`hellowellness-3230`). Digits are pulled out of whatever the
 *   caller passes and matched against the legacy event id and the legacy version id alike, so
 *   `EV3230`, `3230` and a bare version id all work.
 * - **The interesting version often has no registrations.** It reads the version grain, not
 *   `inscripcion`, so a cancelled or empty version still comes back — that is usually the answer
 *   ("it was cancelled"), and a registration-grain search can only return silence.
 */
#[AgentTool(name: 'Intras Event Search', category: 'reporting')]
class EventSearchTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'intras_event_search';

    protected ?string $description = 'Busca versiones de eventos por nombre parcial, código de SIPGO (p. ej. '
        . '"EV3230" o "5911"), o rango de fechas, y devuelve el version_id que necesitan '
        . 'las demás herramientas. Úsala SIEMPRE primero cuando te den el nombre o el '
        . 'código de un evento en vez de un version_id. Tolera nombres con o sin espacios '
        . '("HELLO WELLNESS" encuentra "HELLOWELLNESS"). Incluye versiones canceladas y '
        . 'sin inscritos, porque a menudo esa es la respuesta.';

    private const int DEFAULT_LIMIT = 15;
    private const int MAX_LIMIT = 50;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'nombre',
                type: PropertyType::STRING,
                description: 'Nombre parcial del evento o de la versión. Los espacios y acentos se ignoran.',
                required: false,
            ),
            new ToolProperty(
                name: 'codigo',
                type: PropertyType::STRING,
                description: 'Código de SIPGO: "EV3230" (id del evento) o el id de la versión. Se '
                    . 'extraen los dígitos y se buscan en ambos.',
                required: false,
            ),
            new ToolProperty(
                name: 'desde',
                type: PropertyType::STRING,
                description: 'Fecha de inicio mínima, YYYY-MM-DD.',
                required: false,
            ),
            new ToolProperty(
                name: 'hasta',
                type: PropertyType::STRING,
                description: 'Fecha de inicio máxima, YYYY-MM-DD.',
                required: false,
            ),
            new ToolProperty(
                name: 'estatus',
                type: PropertyType::STRING,
                description: 'Filtra por estatus exacto: Completado, Cancelado, En Progreso..., Pendiente.',
                required: false,
            ),
            new ToolProperty(
                name: 'solo_con_inscritos',
                type: PropertyType::BOOLEAN,
                description: 'Excluye versiones sin inscritos. Por defecto false — una versión '
                    . 'cancelada o vacía suele ser justo lo que se busca.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Máximo de versiones. Por defecto 15, tope 50.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $nombre = null,
        ?string $codigo = null,
        ?string $desde = null,
        ?string $hasta = null,
        ?string $estatus = null,
        ?bool $solo_con_inscritos = null,
        ?int $limit = null
    ): array {
        $nombre = trim((string) $nombre);
        $codigo = trim((string) $codigo);
        $desde = trim((string) $desde);
        $hasta = trim((string) $hasta);
        $estatus = trim((string) $estatus);
        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        if ($nombre === '' && $codigo === '' && $desde === '' && $hasta === '' && $estatus === '') {
            return [
                'status' => 'error',
                'message' => 'Indica al menos un criterio: nombre, codigo, desde/hasta o estatus.',
            ];
        }

        try {
            $rows = $this->query($nombre, $codigo, $desde, $hasta, $estatus, (bool) $solo_con_inscritos, $limit);
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'No se pudo buscar el evento: ' . $e->getMessage()];
        }

        if ($rows === []) {
            return $this->nothingFound($nombre, $codigo, $desde, $hasta);
        }

        return [
            'status' => 'success',
            'criterios' => array_filter(
                compact('nombre', 'codigo', 'desde', 'hasta', 'estatus'),
                static fn (string $value): bool => $value !== ''
            ),
            'total' => count($rows),
            'versiones' => $rows,
            'note' => count($rows) === 1
                ? 'Usa version_id=' . $rows[0]['version_id'] . ' en las demás herramientas.'
                : 'Varias coincidencias: elige la correcta por fecha o estatus y usa su version_id.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function query(
        string $nombre,
        string $codigo,
        string $desde,
        string $hasta,
        string $estatus,
        bool $onlyWithRegistrations,
        int $limit
    ): array {
        $table = sprintf('rpt_evento_version_app%d', $this->app->getId());
        $connection = DB::connection('reporting');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        // The tenant predicate is explicit because this reads the physical table rather than going
        // through ReportQueryService — the squashed-name comparison is not expressible as a filter.
        $query = $connection->table($table)->where('companies_id', $this->company->getId());

        if ($nombre !== '') {
            $needle = $this->squash($nombre);
            $query->where(function ($q) use ($needle): void {
                foreach (['evento', 'version'] as $column) {
                    $q->orWhereRaw(
                        "LOWER(REGEXP_REPLACE({$column}, '[^A-Za-z0-9]', '')) LIKE ?",
                        ['%' . $needle . '%']
                    );
                }
            });
        }

        if ($codigo !== '') {
            $digits = preg_replace('/\D+/', '', $codigo) ?? '';

            if ($digits === '') {
                return [];
            }

            // A bare number is ambiguous: SIPGO shows event ids as "EV3230" and version ids plain,
            // and a caller rarely knows which they are holding. Match both rather than guess.
            $query->where(function ($q) use ($digits): void {
                $q->where('legacy_id', (int) $digits)
                    ->orWhere('codigo_evento', 'like', '%-' . $digits)
                    ->orWhere('codigo_version', 'like', '%-' . $digits);
            });
        }

        if ($desde !== '') {
            $query->where('fecha_inicio', '>=', $desde);
        }

        if ($hasta !== '') {
            $query->where('fecha_inicio', '<=', $hasta);
        }

        if ($estatus !== '') {
            $query->where('estatus', $estatus);
        }

        if ($onlyWithRegistrations) {
            $query->where('inscripciones', '>', 0);
        }

        $rows = $query->orderByDesc('fecha_inicio')->limit($limit)->get();

        return array_map(fn (object $row): array => $this->present($row), $rows->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        return array_filter([
            'version_id' => (int) $row->version_id,
            'legacy_version_id' => $row->legacy_id === null ? null : (int) $row->legacy_id,
            'evento' => $row->evento,
            'version' => $row->version,
            'codigo_evento' => $row->codigo_evento,
            'numero_version' => $row->numero_version === null ? null : (int) $row->numero_version,
            'estatus' => $row->estatus,
            'fecha_inicio' => $row->fecha_inicio,
            'fecha_fin' => $row->fecha_fin,
            'tipo' => $row->tipo,
            'clase' => $row->clase,
            'categoria' => $row->categoria,
            'lugar' => $row->lugar,
            'inscripciones' => (int) $row->inscripciones,
            'asistentes' => (int) $row->asistentes,
            'empresas' => (int) $row->empresas,
            'capacidad' => $row->capacidad === null ? null : (int) $row->capacidad,
            // A cancelled version with zero registrations looks like missing data unless the tool
            // says outright that it is not.
            'aviso' => $this->caveat($row),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function caveat(object $row): ?string
    {
        $cancelled = mb_strtolower(trim((string) $row->estatus)) === 'cancelado';
        $empty = (int) $row->inscripciones === 0;

        return match (true) {
            $cancelled && $empty => 'Versión CANCELADA y sin inscritos: no hay nada que reportar, y '
                . 'eso es un hecho del sistema, no un dato faltante.',
            $cancelled => 'Versión CANCELADA. Sus inscripciones no cuentan como participación.',
            $empty => 'Sin inscritos registrados en SIPGO.',
            default => null,
        };
    }

    /**
     * A bare empty result reads as "try again" and the model re-queries until the run budget
     * kills the turn. Say what was searched and what to change.
     *
     * @return array<string, mixed>
     */
    private function nothingFound(
        string $nombre,
        string $codigo,
        string $desde,
        string $hasta
    ): array {
        $hints = [];

        if ($nombre !== '') {
            $hints[] = 'prueba con menos palabras del nombre (la búsqueda ya ignora espacios y acentos)';
        }

        if ($codigo !== '') {
            $hints[] = 'confirma si el código es del evento o de la versión';
        }

        if ($desde !== '' || $hasta !== '') {
            $hints[] = 'amplía el rango de fechas, o quítalo y filtra por nombre';
        }

        return [
            'status' => 'success',
            'total' => 0,
            'versiones' => [],
            'note' => 'No hay ninguna versión con esos criterios en esta agencia. NO repitas la misma '
                . 'búsqueda: no cambiará el resultado. ' . ($hints === [] ? '' : ucfirst(implode('; ', $hints)) . '. ')
                . 'Si sigue sin aparecer, dile a la persona qué buscaste y pídele otro dato.',
        ];
    }

    /**
     * Letters and digits only, lowercased — "HELLO WELLNESS" and "HELLOWELLNESS" are the same
     * event, and legacy names are inconsistent about spaces, hyphens and accents.
     */
    private function squash(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $ascii === false ? $value : $ascii));
    }
}
