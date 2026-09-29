<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Analytics\Reporting\Services\ReportSchemaService;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\RegistrationMapper;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Guild\Customers\Models\People;
use Override;

/**
 * One row per person per event registration — the table the whole design turns on.
 *
 * The Ejecutivos tab puts up to **17 conditions on the same attendance row** (event, version,
 * type, class, category, theme line, area, affiliate, version status, channel, inscription type,
 * facilitator, plan, date ≥, date ≤, place country, place city). Elasticsearch could not express
 * that: nested children matched independently, so "Seminario AND Q1 2026" returned people who
 * attended a Seminario in 2019 and something else in February. That is why
 * `participatesInEventByInscriptionType()` exists in the legacy code as a MySQL re-check.
 *
 * At this grain it is an ordinary `AND` over one row.
 *
 * Person columns are **copied in** from `ejecutivo`. That duplication is the point — it is what
 * lets every Gestor query be a single-table scan with no join and no EAV pivot.
 */
class InscripcionDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    /**
     * The person side is read back out of the already-flattened `ejecutivo` table, which is
     * per-app — so this definition needs to know which app it is refreshing.
     */
    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'inscripcion';
    }

    #[Override]
    public function label(): string
    {
        return 'Inscripción';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::PERSON_EVENT;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'inscripcion_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ...$this->personColumns(),
            ...$this->eventColumns(),
            ...$this->registrationColumns(),
        ];
    }

    /**
     * Denormalized from the person so an event-scoped filter never has to join back.
     *
     * @return array<int, ReportColumn>
     */
    protected function personColumns(): array
    {
        return [
            ReportColumn::integer('peoples_id', 'Ejecutivo (id)', indexed: true),
            ReportColumn::string('pa_code', 16, 'PA', indexed: true),
            ReportColumn::string('nombre_completo', 256, 'Ejecutivo'),
            ReportColumn::string('email', 255, 'Email'),
            ReportColumn::string('sexo', 16, 'Sexo'),
            ReportColumn::string('posicion', 255, 'Posición'),
            ReportColumn::string('nivel', 64, 'Nivel', indexed: true),
            ReportColumn::string('area', 64, 'Área'),
            ReportColumn::string('estatus_ejecutivo', 64, 'Estatus del ejecutivo'),
            ReportColumn::integer('empresa_id', 'Empresa (id)', indexed: true),
            ReportColumn::string('empresa', 255, 'Empresa'),
            // The company's business sector, copied from `ejecutivo`. Not the barrio — that is
            // `distrito` there and is deliberately not carried onto the registration grain.
            ReportColumn::string('sector', 64, 'Sector empresarial'),
        ];
    }

    /**
     * @return array<int, ReportColumn>
     */
    protected function eventColumns(): array
    {
        return [
            ReportColumn::integer('evento_id', 'Evento (id)'),
            ReportColumn::string('evento', 255, 'Evento'),
            ReportColumn::string('codigo_evento', 255, 'Código de evento'),
            ReportColumn::integer('version_id', 'Versión (id)', indexed: true),
            ReportColumn::string('version', 255, 'Versión'),
            ReportColumn::string('codigo_version', 255, 'Código de versión'),
            ReportColumn::string('tipo', 64, 'Tipo', indexed: true),
            ReportColumn::string('clase', 64, 'Clase'),
            ReportColumn::string('categoria', 64, 'Categoría'),
            ReportColumn::string('linea_tematica', 64, 'Línea temática'),
            ReportColumn::string('area_evento', 64, 'Área del evento'),
            ReportColumn::string('aliado', 128, 'Aliado'),
            ReportColumn::string('programa', 128, 'Programa'),
            ReportColumn::boolean('programa_completado', 'Programa completado'),
            ReportColumn::string('estatus_version', 64, 'Estatus de la versión', indexed: true),
            ReportColumn::string('idioma', 64, 'Idioma'),

            // Facilitators are many per version but only ever carry one filter condition, so a
            // JSON array rather than a grain of their own.
            ReportColumn::jsonArray('facilitadores', 'CHAR(128)', 'Facilitadores'),

            // Every date the version runs on. The Gestor's from/to pair means "attended
            // something dated in this range", which MEMBER OF over this array answers without
            // multiplying the row count by dates per version.
            ReportColumn::jsonArray('fechas', 'DATE', 'Fechas'),
            ReportColumn::date('fecha_inicio', 'Fecha desde', indexed: true),
            ReportColumn::date('fecha_fin', 'Fecha hasta'),

            ReportColumn::string('lugar', 128, 'Lugar'),
            ReportColumn::string('sala', 128, 'Salón'),
            ReportColumn::string('pais_evento', 64, 'País del evento'),
            ReportColumn::string('ciudad_evento', 64, 'Ciudad del evento'),
        ];
    }

    /**
     * @return array<int, ReportColumn>
     */
    protected function registrationColumns(): array
    {
        return [
            ReportColumn::string('tipo_inscripcion', 64, 'Tipo de inscripción', indexed: true),
            ReportColumn::integer('tipo_inscripcion_id'),

            // The business rule, resolved once. Legacy uses IN (1,2,6,7,8,9,11,14) everywhere
            // except participants_profiles, which drops 11 and 14 — two conflicting definitions
            // of "attended" in one system. This column is the single answer.
            ReportColumn::boolean('es_asistente', 'Asistió', indexed: true),

            // reserved_tickets ?: 1. Every legacy report counts seats, not headcount, and
            // re-derives it; here it is computed once.
            ReportColumn::integer('cupos', 'Cupos'),

            ReportColumn::string('canal', 64, 'Canal'),
            ReportColumn::string('como_se_entero', 64, 'Cómo se enteró'),
            ReportColumn::string('sponsor', 128, 'Sponsor'),
            ReportColumn::decimal('precio', 12, 2, 'Precio'),
            ReportColumn::decimal('descuento', 12, 2, 'Descuento'),
            ReportColumn::string('moneda', 3, 'Moneda'),
            ReportColumn::integer('plan_id'),
            ReportColumn::string('plan', 128, 'Plan'),
            ReportColumn::date('fecha_factura', 'Fecha de factura'),
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
            ->table('event_version_participants as evp')
            ->join('participants as pt', 'pt.id', '=', 'evp.participant_id')
            ->join('event_versions as ev', 'ev.id', '=', 'evp.event_version_id')
            ->leftJoin('events as e', 'e.id', '=', 'ev.event_id')
            ->leftJoin('participant_types as ptype', 'ptype.id', '=', 'evp.participant_type_id')
            ->leftJoin(
                DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields as ptcf',
                function ($join) {
                    $join->on('ptcf.entity_id', '=', 'ptype.id')
                        ->where('ptcf.model_name', '=', ParticipantType::class)
                        ->where('ptcf.name', '=', CustomFieldEnum::INTRAS_EVENT_ID->value)
                        ->where('ptcf.is_deleted', '=', 0);
                }
            )
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
            ->where('evp.is_deleted', 0)
            ->where('pt.apps_id', $app->getId())
            ->where('pt.companies_id', $company->getId())
            ->select(
                'evp.id',
                'evp.ticket_price',
                'evp.discount',
                'evp.invoice_date',
                'evp.metadata',
                'evp.participant_type_id',
                'pt.people_id',
                'ev.id as version_id',
                'ev.name as version_name',
                'ev.slug as version_slug',
                'ev.start_at',
                'ev.end_at',
                'ev.currency_id',
                'cur.code as moneda',
                'e.id as evento_id',
                'e.name as evento',
                'e.slug as evento_slug',
                'ptype.name as tipo_inscripcion',
                'ptcf.value as legacy_type_id',
                'et.name as tipo',
                'ec.name as clase',
                'ecat.name as categoria',
                'es.name as estatus_version',
                'ta.name as area_evento',
                'th.name as linea_tematica',
            );

        if ($ids !== null) {
            $query->whereIn('evp.id', $ids);
        }

        foreach ($query->orderBy('evp.id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk->all();

            $peopleIds = array_values(array_unique(array_map(fn ($r) => (int) $r->people_id, $rows)));
            $versionIds = array_values(array_unique(array_map(fn ($r) => (int) $r->version_id, $rows)));
            $registrationIds = array_map(fn ($r) => (int) $r->id, $rows);

            $people = $this->peopleFor($peopleIds);
            $versionFields = $this->versionCustomFieldsFor($versionIds);
            $registrationFields = $this->registrationCustomFieldsFor($registrationIds);
            $dates = $this->datesFor($versionIds);
            $facilitators = $this->facilitatorsFor($versionIds);

            foreach ($rows as $row) {
                yield $this->registrationRow(
                    $row,
                    $company,
                    $people[(int) $row->people_id] ?? null,
                    $versionFields[(int) $row->version_id] ?? [],
                    $registrationFields[(int) $row->id] ?? [],
                    $dates[(int) $row->version_id] ?? [],
                    $facilitators[(int) $row->version_id] ?? [],
                );
            }
        }
    }

    /**
     * An EventVersion date change touches every registration for it, and a person's details
     * touch every registration they hold — the two fan-outs this grain has.
     *
     * @return array<class-string, callable(object): array<int, int>>
     */
    #[Override]
    public function invalidatedBy(): array
    {
        return [
            EventVersion::class => fn (EventVersion $version): array => DB::connection('event')
                ->table('event_version_participants')
                ->where('event_version_id', $version->getId())
                ->where('is_deleted', 0)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            People::class => fn (People $person): array => DB::connection('event')
                ->table('event_version_participants as evp')
                ->join('participants as pt', 'pt.id', '=', 'evp.participant_id')
                ->where('pt.people_id', $person->getId())
                ->where('evp.is_deleted', 0)
                ->pluck('evp.id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /**
     * The person side, denormalized onto every registration so no event filter has to join back.
     *
     * Reads the already-flattened `ejecutivo` table rather than re-pivoting the EAV: it is the
     * same data, one table, and it keeps the two grains consistent by construction.
     *
     * @param array<int, int> $peopleIds
     *
     * @return array<int, object>
     */
    protected function peopleFor(array $peopleIds): array
    {
        if ($peopleIds === []) {
            return [];
        }

        $rows = DB::connection(ReportSchemaService::CONNECTION)
            ->table(new ReportSchemaService()->tableFor(new EjecutivoDefinition(), $this->appId))
            ->whereIn('peoples_id', $peopleIds)
            ->select(
                'peoples_id',
                'pa_code',
                'nombre_completo',
                'email',
                'sexo',
                'posicion',
                'nivel',
                'area',
                'estatus',
                'empresa_id',
                'empresa',
                'sector'
            )
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->peoples_id] = $row;
        }

        return $map;
    }

    /**
     * @param array<int, int> $versionIds
     *
     * @return array<int, array<string, string>>
     */
    protected function versionCustomFieldsFor(array $versionIds): array
    {
        return $this->customFieldsFor(EventVersion::class, $versionIds);
    }

    /**
     * @param array<int, int> $registrationIds
     *
     * @return array<int, array<string, string>>
     */
    protected function registrationCustomFieldsFor(array $registrationIds): array
    {
        return $this->customFieldsFor(EventVersionParticipant::class, $registrationIds);
    }

    /**
     * Not filtered by companies_id — that column records the writer's company context, not the
     * entity's owner. See EjecutivoDefinition::customFieldsFor().
     *
     * @param array<int, int> $entityIds
     *
     * @return array<int, array<string, string>>
     */
    protected function customFieldsFor(string $modelName, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $rows = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', $modelName)
            ->whereIn('entity_id', $entityIds)
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
     * Every date a version runs on.
     *
     * Kept as an array on the registration rather than becoming a third grain — the Gestor's
     * from/to pair means "attended something dated in this range", which MEMBER OF answers
     * without multiplying rows by dates-per-version.
     *
     * @param array<int, int> $versionIds
     *
     * @return array<int, array<int, string>>
     */
    protected function datesFor(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

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
        if ($versionIds === []) {
            return [];
        }

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
     * Assemble one registration row.
     *
     * @param array<string, string> $versionFields
     * @param array<string, string> $registrationFields
     * @param array<int, string> $dates
     * @param array<int, string> $facilitators
     *
     * @return array<string, mixed>
     */
    protected function registrationRow(
        object $row,
        Companies $company,
        ?object $person,
        array $versionFields,
        array $registrationFields,
        array $dates,
        array $facilitators
    ): array {
        $metadata = $this->decodeMetadata($row->metadata ?? null);
        // The legacy inscriptions_types.id is stored on the synced ParticipantType, not on the
        // registration — `es_asistente` is defined in terms of those legacy ids.
        $typeId = $row->legacy_type_id ?? null;

        return [
            'inscripcion_id' => (int) $row->id,
            'companies_id' => $company->getId(),

            'peoples_id' => (int) $row->people_id,
            'pa_code' => $person?->pa_code,
            'nombre_completo' => $person?->nombre_completo,
            'email' => $person?->email,
            'sexo' => $person?->sexo,
            'posicion' => $person?->posicion,
            'nivel' => $person?->nivel,
            'area' => $person?->area,
            'estatus_ejecutivo' => $person?->estatus,
            'empresa_id' => $person?->empresa_id,
            'empresa' => $person?->empresa,
            'sector' => $person?->sector,

            'evento_id' => $row->evento_id === null ? null : (int) $row->evento_id,
            'evento' => $row->evento,
            'codigo_evento' => $row->evento_slug,
            'version_id' => (int) $row->version_id,
            'version' => $row->version_name,
            'codigo_version' => $row->version_slug,
            'tipo' => $row->tipo,
            'clase' => $row->clase,
            'categoria' => $row->categoria,
            'linea_tematica' => $row->linea_tematica,
            'area_evento' => $row->area_evento,
            'aliado' => $versionFields['aliado'] ?? null,
            'programa' => $registrationFields['programa'] ?? null,
            'programa_completado' => $this->flag($registrationFields['programa_completado'] ?? null),
            'estatus_version' => $row->estatus_version,
            'idioma' => $versionFields['idioma'] ?? null,

            'facilitadores' => $facilitators,
            'fechas' => $dates,
            // Prefer the version's own range; fall back to the dates when it was never set.
            'fecha_inicio' => $this->dateOnly($row->start_at) ?? ($dates[0] ?? null),
            'fecha_fin' => $this->dateOnly($row->end_at) ?? ($dates === [] ? null : end($dates)),

            'lugar' => $versionFields['lugar'] ?? null,
            'sala' => $versionFields['sala'] ?? null,
            'pais_evento' => $versionFields['pais_evento'] ?? null,
            'ciudad_evento' => $versionFields['ciudad_evento'] ?? null,

            'tipo_inscripcion' => $row->tipo_inscripcion,
            'tipo_inscripcion_id' => $typeId === null ? null : (int) $typeId,
            // The single answer to "did they attend", resolved once rather than re-derived by
            // every report — two of SIPGO's own disagree about it.
            'es_asistente' => RegistrationMapper::isAttending($typeId === null ? null : (int) $typeId) ? 1 : 0,
            // reserved_tickets ?: 1 — seats, not headcount.
            'cupos' => max(1, (int) ($metadata['reserved_tickets'] ?? 1)),

            // These four come from `metadata`, not from custom fields. `canal` alone applies to
            // 54,089 registrations — as a custom field that is 54k extra rows in
            // `apps_custom_fields` for a value read once per rebuild, so the importer writes the
            // resolved name into the registration's own metadata column instead.
            'canal' => $metadata['canal'] ?? $registrationFields['canal'] ?? null,
            // No source: SIPGO has no "how did you hear about us" field on a registration.
            'como_se_entero' => $registrationFields['como_se_entero'] ?? null,
            'sponsor' => $metadata['sponsor'] ?? $registrationFields['sponsor'] ?? null,
            'precio' => $row->ticket_price,
            'descuento' => $row->discount,
            // A registration has no currency of its own; it is priced in the version's.
            'moneda' => $row->moneda ?? null,
            'plan_id' => isset($metadata['plan_id']) ? (int) $metadata['plan_id'] : null,
            'plan' => $metadata['plan'] ?? $registrationFields['plan'] ?? null,
            'fecha_factura' => $this->dateOnly($row->invoice_date),
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

    /**
     * Custom fields come back as strings, so "0" must stay false.
     */
    protected function flag(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array((string) $value, ['1', 'true'], true) ? 1 : 0;
    }
}
