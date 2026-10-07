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
use Kanvas\Event\Facilitators\Models\Facilitator;
use Override;

/**
 * One row per facilitator per event version they taught.
 *
 * The Facilitadores tab puts six conditions on the *same* assignment — theme line, area, event
 * type, class, status and date range. At facilitator grain those match across different
 * assignments, which is the same defect that made "Seminario AND Q1" wrong on the Ejecutivos tab.
 *
 * Facilitator attributes are copied in so an event-scoped filter never joins back.
 */
class FacilitadorAsignacionDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'facilitador_asignacion';
    }

    #[Override]
    public function label(): string
    {
        return 'Asignación de facilitador';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::FACILITATOR_ASSIGNMENT;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'asignacion_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('facilitator_id', 'Facilitador (id)', indexed: true),
            ReportColumn::string('facilitador', 256, 'Facilitador', indexed: true),
            ReportColumn::string('aliado_facilitador', 128, 'Aliado del facilitador'),
            ReportColumn::string('pais_facilitador', 64, 'País del facilitador'),

            ReportColumn::integer('version_id', 'Versión (id)', indexed: true),
            ReportColumn::string('evento', 255, 'Evento'),
            ReportColumn::string('version', 255, 'Versión'),
            ReportColumn::string('tipo', 64, 'Tipo', indexed: true),
            ReportColumn::string('clase', 64, 'Clase', indexed: true),
            ReportColumn::string('categoria', 64, 'Categoría'),
            ReportColumn::string('linea_tematica', 64, 'Línea temática', indexed: true),
            ReportColumn::string('area', 64, 'Área', indexed: true),
            ReportColumn::string('estatus_version', 64, 'Estatus de la versión', indexed: true),
            ReportColumn::string('aliado', 128, 'Aliado del evento'),

            ReportColumn::jsonArray('fechas', 'DATE', 'Fechas'),
            ReportColumn::date('fecha_inicio', 'Fecha desde', indexed: true),
            ReportColumn::date('fecha_fin', 'Fecha hasta'),

            ReportColumn::integer('inscripciones', 'Inscripciones'),
            ReportColumn::decimal('satisfaccion', 4, 2, 'Satisfacción'),
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
            ->table('event_version_facilitators as evf')
            ->join('facilitators as f', 'f.id', '=', 'evf.facilitator_id')
            ->join('event_versions as ev', 'ev.id', '=', 'evf.event_version_id')
            ->join(DB::connection('crm')->getDatabaseName() . '.peoples as p', 'p.id', '=', 'f.people_id')
            ->where('f.apps_id', $app->getId())
            ->where('f.companies_id', $company->getId())
            ->where('evf.is_deleted', 0)
            ->select(
                'evf.id',
                'evf.facilitator_id',
                'evf.event_version_id',
                'f.people_id',
                'p.firstname',
                'p.lastname',
            );

        if ($ids !== null) {
            $query->whereIn('evf.id', $ids);
        }

        foreach ($query->orderBy('evf.id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk->all();
            $versionIds = array_values(array_unique(array_map(fn ($r) => (int) $r->event_version_id, $rows)));
            $peopleIds = array_values(array_unique(array_map(fn ($r) => (int) $r->people_id, $rows)));

            // Both sides read back out of the already-flattened tables, so a drill-down from
            // this grain cannot disagree with the tab it drilled from.
            $versions = $this->flatRows('evento_version', 'version_id', $versionIds);
            $facilitators = $this->flatRows('facilitador', 'peoples_id', $peopleIds);

            foreach ($rows as $row) {
                $version = $versions[(int) $row->event_version_id] ?? null;
                $facilitator = $facilitators[(int) $row->people_id] ?? null;

                yield [
                    'asignacion_id' => (int) $row->id,
                    'companies_id' => $company->getId(),

                    'facilitator_id' => (int) $row->facilitator_id,
                    'facilitador' => trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')),
                    'aliado_facilitador' => $facilitator?->aliado,
                    'pais_facilitador' => $facilitator?->pais,

                    'version_id' => (int) $row->event_version_id,
                    'evento' => $version?->evento,
                    'version' => $version?->version,
                    'tipo' => $version?->tipo,
                    'clase' => $version?->clase,
                    'categoria' => $version?->categoria,
                    'linea_tematica' => $version?->linea_tematica,
                    'area' => $version?->area,
                    'estatus_version' => $version?->estatus,
                    'aliado' => $version?->aliado,

                    'fechas' => $this->decodeArray($version?->fechas),
                    'fecha_inicio' => $version?->fecha_inicio,
                    'fecha_fin' => $version?->fecha_fin,

                    'inscripciones' => $version?->inscripciones ?? 0,
                    'satisfaccion' => $version?->satisfaccion_facilitadores,
                ];
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
            EventVersion::class => fn (EventVersion $version): array => DB::connection('event')
                ->table('event_version_facilitators')
                ->where('event_version_id', $version->getId())
                ->where('is_deleted', 0)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            Facilitator::class => fn (Facilitator $facilitator): array => DB::connection('event')
                ->table('event_version_facilitators')
                ->where('facilitator_id', $facilitator->getId())
                ->where('is_deleted', 0)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /**
     * @param array<int, int> $ids
     *
     * @return array<int, object>
     */
    protected function flatRows(string $model, string $keyColumn, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $table = sprintf('rpt_%s_app%d', $model, $this->appId);
        $connection = DB::connection('reporting');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $map = [];

        foreach ($connection->table($table)->whereIn($keyColumn, $ids)->get() as $row) {
            $map[(int) $row->{$keyColumn}] = $row;
        }

        return $map;
    }

    /**
     * @return array<int, mixed>
     */
    protected function decodeArray(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
