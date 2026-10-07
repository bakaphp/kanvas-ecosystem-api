<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Guild\Customers\Models\People;
use Override;

/**
 * The Gestor's Facilitadores tab, flattened.
 *
 * Facilitator-only filters answer from here. The six correlated conditions on a single teaching
 * assignment live in `facilitador_asignacion`, for the same reason `inscripcion` exists.
 */
class FacilitadorDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'facilitador';
    }

    #[Override]
    public function label(): string
    {
        return 'Facilitador';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::FACILITATOR;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'facilitator_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('legacy_id'),
            ReportColumn::integer('peoples_id', 'Persona (id)', indexed: true),
            ReportColumn::string('nombre', 128, 'Nombre'),
            ReportColumn::string('apellido', 128, 'Apellido'),
            ReportColumn::string('nombre_completo', 256, 'Facilitador', indexed: true),
            ReportColumn::string('cedula', 32, 'Identificación'),
            ReportColumn::string('sexo', 16, 'Sexo'),
            ReportColumn::text('resumen'),

            ReportColumn::string('estatus', 64, 'Estatus', indexed: true),
            ReportColumn::string('aliado', 128, 'Aliado', indexed: true),
            ReportColumn::string('pais', 64, 'País', indexed: true),
            ReportColumn::string('ciudad', 64, 'Ciudad', indexed: true),
            ReportColumn::string('empresa', 255, 'Empresa'),

            ReportColumn::string('email', 255, 'Email'),
            ReportColumn::string('telefono', 32, 'Teléfono'),
            ReportColumn::string('celular', 32, 'Celular'),

            // Single-condition sets — JSON rather than their own grain.
            ReportColumn::jsonArray('temas', 'CHAR(128)', 'Temas'),
            ReportColumn::jsonArray('idiomas', 'CHAR(64)', 'Idiomas'),

            ReportColumn::string('creado_por', 128, 'Creado por'),
            ReportColumn::datetime('creado_en', 'Creado en'),

            // Precomputed so the tab never aggregates on read.
            ReportColumn::integer('total_versiones', 'Versiones impartidas'),
            ReportColumn::date('ultima_version', 'Última versión'),
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
            ->table('facilitators as f')
            ->join(DB::connection('crm')->getDatabaseName() . '.peoples as p', 'p.id', '=', 'f.people_id')
            ->where('f.apps_id', $app->getId())
            ->where('f.companies_id', $company->getId())
            ->where('f.is_deleted', 0)
            ->select(
                'f.id',
                'f.people_id',
                'f.identification',
                'f.resume',
                'p.firstname',
                'p.lastname',
            );

        if ($ids !== null) {
            $query->whereIn('f.id', $ids);
        }

        foreach ($query->orderBy('f.id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk->all();
            $facilitatorIds = array_map(fn ($r) => (int) $r->id, $rows);
            $peopleIds = array_map(fn ($r) => (int) $r->people_id, $rows);

            $facilitatorFields = $this->customFieldsFor(Facilitator::class, $facilitatorIds);
            $peopleFields = $this->customFieldsFor(People::class, $peopleIds);
            $contacts = $this->contactsFor($peopleIds);
            $tags = $this->tagsFor($peopleIds);
            $rollups = $this->rollupsFor($facilitatorIds);

            foreach ($rows as $row) {
                yield $this->row(
                    $row,
                    $company,
                    $facilitatorFields[(int) $row->id] ?? [],
                    $peopleFields[(int) $row->people_id] ?? [],
                    $contacts[(int) $row->people_id] ?? [],
                    $tags[(int) $row->people_id] ?? [],
                    $rollups[(int) $row->id] ?? [],
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
            Facilitator::class => fn (Facilitator $facilitator): array => [(int) $facilitator->getId()],
            People::class => fn (People $person): array => DB::connection('event')
                ->table('facilitators')
                ->where('people_id', $person->getId())
                ->where('is_deleted', 0)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /**
     * Not company-scoped — apps_custom_fields.companies_id records the writer's context.
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
     * @param array<int, int> $peopleIds
     *
     * @return array<int, array<string, string>>
     */
    protected function contactsFor(array $peopleIds): array
    {
        $rows = DB::connection('crm')
            ->table('peoples_contacts as pc')
            ->join('contacts_types as ct', 'ct.id', '=', 'pc.contacts_types_id')
            ->whereIn('pc.peoples_id', $peopleIds)
            ->where('pc.is_deleted', 0)
            ->select('pc.peoples_id', 'ct.name as type', 'pc.value')
            ->orderBy('pc.weight')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->peoples_id][strtolower((string) $row->type)] ??= (string) $row->value;
        }

        return $map;
    }

    /**
     * A custom field holding a JSON array comes back as a string; anything else is not a list.
     *
     * @return list<string>
     */
    protected function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    protected function tagsFor(array $peopleIds): array
    {
        $rows = DB::connection('social')
            ->table('tags_entities as te')
            ->join('tags as t', 't.id', '=', 'te.tags_id')
            ->whereIn('te.entity_id', $peopleIds)
            ->where('te.is_deleted', 0)
            ->select('te.entity_id', 't.name')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->entity_id][] = (string) $row->name;
        }

        return $map;
    }

    /**
     * @param array<int, int> $facilitatorIds
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rollupsFor(array $facilitatorIds): array
    {
        $rows = DB::connection('event')
            ->table('event_version_facilitators as evf')
            ->join('event_versions as ev', 'ev.id', '=', 'evf.event_version_id')
            ->whereIn('evf.facilitator_id', $facilitatorIds)
            ->where('evf.is_deleted', 0)
            ->groupBy('evf.facilitator_id')
            ->selectRaw('evf.facilitator_id, COUNT(*) as total, MAX(ev.start_at) as ultima')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->facilitator_id] = [
                'total_versiones' => (int) $row->total,
                'ultima_version' => $row->ultima === null ? null : substr((string) $row->ultima, 0, 10),
            ];
        }

        return $map;
    }

    /**
     * @param array<string, string> $facilitatorFields
     * @param array<string, string> $peopleFields
     * @param array<string, string> $contacts
     * @param array<int, string> $tags
     * @param array<string, mixed> $rollups
     *
     * @return array<string, mixed>
     */
    protected function row(
        object $row,
        Companies $company,
        array $facilitatorFields,
        array $peopleFields,
        array $contacts,
        array $tags,
        array $rollups
    ): array {
        $ff = fn (string $n) => $facilitatorFields[$n] ?? null;
        $pf = fn (string $n) => $peopleFields[$n] ?? null;

        return [
            'facilitator_id' => (int) $row->id,
            'companies_id' => $company->getId(),

            'legacy_id' => $ff('INTRAS_FACILITATOR_ID') === null ? null : (int) $ff('INTRAS_FACILITATOR_ID'),
            'peoples_id' => (int) $row->people_id,
            'nombre' => $row->firstname,
            'apellido' => $row->lastname,
            'nombre_completo' => trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? '')),
            'cedula' => $row->identification,
            'sexo' => $pf('sexo'),
            'resumen' => $row->resume,

            'estatus' => $pf('estatus'),
            'aliado' => $pf('aliado'),
            'pais' => $pf('pais'),
            'ciudad' => $pf('ciudad'),
            'empresa' => $pf('empresa'),

            'email' => $contacts['email'] ?? $contacts['secondary_email'] ?? null,
            'telefono' => $contacts['phone'] ?? $contacts['work_phone'] ?? null,
            'celular' => $contacts['cellphone'] ?? null,

            // Kanvas tags plus SIPGO's own expertise tables — `facilitators_themes_areas`
            // (7,145 rows) and `facilitators_keywords` (1,817), merged by the importer into one
            // `temas` custom field because the Gestor treats them as one filter. Tags alone left
            // this null on all 1,672 rows, so "¿qué facilitador puede impartir el tema X?" had
            // nothing to match.
            'temas' => array_values(array_unique(array_merge($tags, $this->decodeList($ff('temas') ?? $pf('temas'))))),
            'idiomas' => $this->decodeList($ff('idiomas') ?? $pf('idiomas')),

            'creado_por' => $pf('creado_por'),
            'creado_en' => $pf('creado_en'),

            'total_versiones' => $rollups['total_versiones'] ?? 0,
            'ultima_version' => $rollups['ultima_version'] ?? null,
        ];
    }
}
