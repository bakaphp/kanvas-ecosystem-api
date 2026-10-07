<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Override;

/**
 * The Gestor's Eventos tab, flattened.
 *
 * One row per event version — the version, not the event, is what the tab searches and what
 * everything else hangs off. Seat and attendance rollups are precomputed so the tab never
 * aggregates on read, and `asistentes` uses the single resolved definition of "attended" rather
 * than each caller re-deriving it.
 */
class EventoVersionDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'evento_version';
    }

    #[Override]
    public function label(): string
    {
        return 'Versión de evento';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::EVENT_VERSION;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'version_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('legacy_id'),
            ReportColumn::integer('evento_id', 'Evento (id)', indexed: true),
            ReportColumn::string('evento', 255, 'Evento', indexed: true),
            ReportColumn::string('codigo_evento', 255, 'Código de evento'),
            ReportColumn::string('version', 255, 'Versión'),
            ReportColumn::string('codigo_version', 255, 'Código de versión'),
            ReportColumn::integer('numero_version', 'Número de versión'),

            ReportColumn::string('tipo', 64, 'Tipo', indexed: true),
            ReportColumn::string('clase', 64, 'Clase', indexed: true),
            ReportColumn::string('categoria', 64, 'Categoría', indexed: true),
            ReportColumn::string('linea_tematica', 64, 'Línea temática', indexed: true),
            ReportColumn::string('area', 64, 'Área', indexed: true),
            ReportColumn::string('estatus', 64, 'Estatus', indexed: true),
            ReportColumn::string('aliado', 128, 'Aliado', indexed: true),
            ReportColumn::string('idioma', 64, 'Idioma'),
            ReportColumn::string('clasificacion', 32, 'Clasificación'),
            ReportColumn::string('tipo_montaje', 64, 'Tipo de montaje'),

            ReportColumn::string('lugar', 128, 'Lugar'),
            ReportColumn::string('sala', 128, 'Salón'),
            ReportColumn::string('pais', 64, 'País', indexed: true),
            ReportColumn::string('ciudad', 64, 'Ciudad', indexed: true),
            ReportColumn::text('comentarios_lugar'),

            // Every date the version runs on. One row stays one version; the from/to filter is
            // answered with MEMBER OF rather than a date-level grain.
            ReportColumn::jsonArray('fechas', 'DATE', 'Fechas'),
            ReportColumn::date('fecha_inicio', 'Fecha desde', indexed: true),
            ReportColumn::date('fecha_fin', 'Fecha hasta'),

            ReportColumn::jsonArray('facilitadores', 'CHAR(128)', 'Facilitadores'),

            ReportColumn::decimal('precio', 12, 2, 'Precio por cupo'),
            ReportColumn::string('moneda', 8, 'Moneda'),
            ReportColumn::decimal('tasa_cambio', 12, 4, 'Tasa de cambio'),
            ReportColumn::integer('capacidad', 'Capacidad máxima'),

            ReportColumn::decimal('satisfaccion_participantes', 4, 2, 'Satisfacción participantes'),
            ReportColumn::decimal('satisfaccion_facilitadores', 4, 2, 'Satisfacción facilitadores'),
            ReportColumn::decimal('satisfaccion_empresas', 4, 2, 'Satisfacción empresas'),

            // Precomputed: inscripciones counts rows, asistentes applies the resolved attendance
            // rule, cupos sums seats rather than headcount.
            ReportColumn::integer('inscripciones', 'Inscripciones'),
            ReportColumn::integer('asistentes', 'Asistentes'),
            ReportColumn::integer('cupos', 'Cupos'),
            ReportColumn::integer('empresas', 'Empresas'),
            ReportColumn::decimal('ingreso', 14, 2, 'Ingreso'),
        ];
    }

    /**
     * @param array<int, int>|null $ids
     *
     * @return iterable<array<string, mixed>>
     */
    #[Override]
    public function rowsFor(AppInterface $app, Companies $company, ?array $ids = null): iterable
    {
        $query = DB::connection('event')
            ->table('event_versions as ev')
            ->leftJoin('events as e', 'e.id', '=', 'ev.event_id')
            ->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
            ->leftJoin('event_classes as ec', 'ec.id', '=', 'e.event_class_id')
            ->leftJoin('event_categories as ecat', 'ecat.id', '=', 'e.event_category_id')
            ->leftJoin('event_statuses as es', 'es.id', '=', 'ev.event_status_id')
            ->leftJoin('theme_areas as ta', 'ta.id', '=', 'e.theme_area_id')
            ->leftJoin('themes as th', 'th.id', '=', 'e.theme_id')
            ->leftJoin(
                DB::connection('ecosystem')->getDatabaseName() . '.currencies as cur',
                'cur.id',
                '=',
                'ev.currency_id'
            )
            ->where('ev.apps_id', $app->getId())
            ->where('ev.companies_id', $company->getId())
            ->where('ev.is_deleted', 0)
            ->select(
                'ev.id',
                'ev.name as version_name',
                'ev.slug as version_slug',
                'ev.version_number',
                'ev.classification',
                'ev.price_per_ticket',
                'ev.participants_satisfaction',
                'ev.places_comments',
                'ev.start_at',
                'ev.end_at',
                'ev.metadata',
                'e.id as evento_id',
                'e.name as evento',
                'e.slug as evento_slug',
                'et.name as tipo',
                'ec.name as clase',
                'ecat.name as categoria',
                'es.name as estatus',
                'ta.name as area',
                'th.name as linea_tematica',
                'cur.code as moneda',
            );

        if ($ids !== null) {
            $query->whereIn('ev.id', $ids);
        }

        foreach ($query->orderBy('ev.id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk->all();
            $versionIds = array_map(fn ($r) => (int) $r->id, $rows);

            $customFields = $this->customFieldsFor($versionIds);
            $dates = $this->datesFor($versionIds);
            $facilitators = $this->facilitatorsFor($versionIds);
            $rollups = $this->rollupsFor($versionIds);

            foreach ($rows as $row) {
                $id = (int) $row->id;

                yield $this->row(
                    $row,
                    $company,
                    $customFields[$id] ?? [],
                    $dates[$id] ?? [],
                    $facilitators[$id] ?? [],
                    $rollups[$id] ?? [],
                );
            }
        }
    }

    /**
     * @return array<class-string, callable(object): array<int, int>>
     */
    #[Override]
    public function invalidatedBy(): array
    {
        return [
            EventVersion::class => fn (EventVersion $version): array => [(int) $version->getId()],
        ];
    }

    /**
     * @param array<int, int> $versionIds
     *
     * @return array<int, array<string, string>>
     */
    protected function customFieldsFor(array $versionIds): array
    {
        // Not company-scoped — apps_custom_fields.companies_id records the writer's context.
        $rows = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', EventVersion::class)
            ->whereIn('entity_id', $versionIds)
            ->where('is_deleted', 0)
            ->select('entity_id', 'name', 'value')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->entity_id][(string) $row->name] = (string) $row->value;
        }

        return $map;
    }

    /**
     * @param array<int, int> $versionIds
     *
     * @return array<int, array<int, string>>
     */
    protected function datesFor(array $versionIds): array
    {
        $rows = DB::connection('event')
            ->table('event_version_dates')
            ->whereIn('event_version_id', $versionIds)
            ->where('is_deleted', 0)
            ->orderBy('event_date')
            ->select('event_version_id', 'event_date')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $date = substr((string) $row->event_date, 0, 10);
            $versionId = (int) $row->event_version_id;

            if (! in_array($date, $map[$versionId] ?? [], true)) {
                $map[$versionId][] = $date;
            }
        }

        return $map;
    }

    /**
     * @param array<int, int> $versionIds
     *
     * @return array<int, array<int, string>>
     */
    protected function facilitatorsFor(array $versionIds): array
    {
        $rows = DB::connection('event')
            ->table('event_version_facilitators as evf')
            ->join('facilitators as f', 'f.id', '=', 'evf.facilitator_id')
            ->join(DB::connection('crm')->getDatabaseName() . '.peoples as p', 'p.id', '=', 'f.people_id')
            ->whereIn('evf.event_version_id', $versionIds)
            ->where('evf.is_deleted', 0)
            ->selectRaw("evf.event_version_id, TRIM(CONCAT(COALESCE(p.firstname,''),' ',COALESCE(p.lastname,''))) as name")
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $name = trim((string) $row->name);

            if ($name === '' || in_array($name, $map[(int) $row->event_version_id] ?? [], true)) {
                continue;
            }

            $map[(int) $row->event_version_id][] = $name;
        }

        return $map;
    }

    /**
     * Attendance rollups, read back out of the already-flattened `inscripcion` table.
     *
     * Deriving them from the same place the Gestor reads is what keeps the tab's totals and a
     * drill-down into the registrations from disagreeing.
     *
     * @param array<int, int> $versionIds
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rollupsFor(array $versionIds): array
    {
        $table = sprintf('rpt_inscripcion_app%d', $this->appId);
        $connection = DB::connection('reporting');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $rows = $connection->table($table)
            ->whereIn('version_id', $versionIds)
            ->groupBy('version_id')
            ->selectRaw(
                'version_id, COUNT(*) as inscripciones, SUM(es_asistente) as asistentes,'
                . ' SUM(cupos) as cupos, COUNT(DISTINCT empresa_id) as empresas,'
                . ' SUM(COALESCE(precio,0) - COALESCE(descuento,0)) as ingreso'
            )
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->version_id] = [
                'inscripciones' => (int) $row->inscripciones,
                'asistentes' => (int) $row->asistentes,
                'cupos' => (int) $row->cupos,
                'empresas' => (int) $row->empresas,
                'ingreso' => $row->ingreso,
            ];
        }

        return $map;
    }

    /**
     * @param array<string, string> $customFields
     * @param array<int, string> $dates
     * @param array<int, string> $facilitators
     * @param array<string, mixed> $rollups
     *
     * @return array<string, mixed>
     */
    protected function row(
        object $row,
        Companies $company,
        array $customFields,
        array $dates,
        array $facilitators,
        array $rollups
    ): array {
        $cf = fn (string $name, mixed $default = null) => $customFields[$name] ?? $default;
        $metadata = $this->decodeMetadata($row->metadata ?? null);

        return [
            'version_id' => (int) $row->id,
            'companies_id' => $company->getId(),

            'legacy_id' => $cf('INTRAS_EVENT_VERSION_ID') === null ? null : (int) $cf('INTRAS_EVENT_VERSION_ID'),
            'evento_id' => $row->evento_id === null ? null : (int) $row->evento_id,
            'evento' => $row->evento,
            'codigo_evento' => $row->evento_slug,
            'version' => $row->version_name,
            'codigo_version' => $row->version_slug,
            'numero_version' => $row->version_number === null ? null : (int) $row->version_number,

            'tipo' => $row->tipo,
            'clase' => $row->clase,
            'categoria' => $row->categoria,
            'linea_tematica' => $row->linea_tematica,
            'area' => $row->area,
            'estatus' => $row->estatus,
            'aliado' => $cf('aliado'),
            'idioma' => $cf('idioma'),
            'clasificacion' => $row->classification,
            'tipo_montaje' => $cf('tipo_montaje'),

            'lugar' => $cf('lugar'),
            'sala' => $cf('sala'),
            'pais' => $cf('pais_evento'),
            'ciudad' => $cf('ciudad_evento'),
            'comentarios_lugar' => $row->places_comments,

            'fechas' => $dates,
            'fecha_inicio' => $this->dateOnly($row->start_at) ?? ($dates[0] ?? null),
            'fecha_fin' => $this->dateOnly($row->end_at) ?? ($dates === [] ? null : end($dates)),

            'facilitadores' => $facilitators,

            'precio' => $row->price_per_ticket,
            'moneda' => $row->moneda,
            'tasa_cambio' => $cf('tasa_cambio'),
            'capacidad' => (int) ($metadata['max_capacity'] ?? 0),

            'satisfaccion_participantes' => $row->participants_satisfaction,
            'satisfaccion_facilitadores' => $cf('satisfaccion_facilitadores'),
            'satisfaccion_empresas' => $cf('satisfaccion_empresas'),

            'inscripciones' => $rollups['inscripciones'] ?? 0,
            'asistentes' => $rollups['asistentes'] ?? 0,
            'cupos' => $rollups['cupos'] ?? 0,
            'empresas' => $rollups['empresas'] ?? 0,
            'ingreso' => $rollups['ingreso'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function dateOnly(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : substr($value, 0, 10);
    }
}
