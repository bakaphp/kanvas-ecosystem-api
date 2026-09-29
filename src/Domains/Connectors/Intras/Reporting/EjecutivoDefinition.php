<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Override;

/**
 * The Gestor's Ejecutivos tab, flattened.
 *
 * Columns are the union of three overlapping sets — the 66 filters, the result-grid columns, and
 * the `moreParamsFields` export list. The export list is authoritative for display and carries
 * fields no filter mentions (`companies.rnc`, the certificado name parts).
 *
 * Person-only filters answer from here. Anything touching events answers from `inscripcion`,
 * which repeats these person columns so that no query has to join.
 */
class EjecutivoDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    /**
     * Kanvas's own person taxonomy for this company — Participant / Key Contact / Facilitator.
     * Loaded once per run in rowsFor().
     *
     * @var array<int, string>
     */
    protected array $peopleTypeNames = [];

    /**
     * Definitions take the app id so the registry can construct them uniformly; the physical
     * table is per-app, and `inscripcion` reads this one back by name.
     */
    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'ejecutivo';
    }

    #[Override]
    public function label(): string
    {
        return 'Ejecutivo';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::PERSON;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'peoples_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            // Identity. `pa_code` is the legacy participants.id — SIPGO has no separate code
            // column, and the grid's "Código" is literally that id.
            ReportColumn::string('pa_code', 16, 'PA', indexed: true),
            ReportColumn::integer('legacy_id'),

            // Name, including the four certificate parts the export prints.
            ReportColumn::string('nombre', 128, 'Nombre'),
            ReportColumn::string('apellido', 128, 'Apellido'),
            ReportColumn::string('nombre_completo', 256, 'Nombre completo', indexed: true),
            ReportColumn::string('cert_primer_nombre', 128),
            ReportColumn::string('cert_segundo_nombre', 128),
            ReportColumn::string('cert_primer_apellido', 128),
            ReportColumn::string('cert_segundo_apellido', 128),

            ReportColumn::string('cedula', 32, 'Cédula'),
            // Not just m/f: the legacy enum also carries PENDIENTE (and a stray 'P').
            ReportColumn::string('sexo', 16, 'Sexo'),
            ReportColumn::date('dob', 'Fecha de nacimiento'),
            // Stored in SIPGO as their own custom fields, not derived from dob — the Gestor
            // filters the stored values and they can disagree.
            ReportColumn::integer('dob_mes', 'Mes de cumpleaños', indexed: true),
            ReportColumn::integer('dob_dia', 'Día de cumpleaños'),

            ReportColumn::string('posicion', 255, 'Posición'),
            ReportColumn::string('nivel', 64, 'Nivel', indexed: true),
            ReportColumn::string('area', 64, 'Área', indexed: true),
            ReportColumn::string('linea_tematica', 64, 'Línea temática'),
            // Free text in SIPGO despite the _id name; the Gestor filters it with LIKE.
            ReportColumn::string('departamento', 254, 'Departamento'),
            ReportColumn::string('profesion', 64, 'Profesión'),
            ReportColumn::string('tipo_regalo', 64, 'Tipo de regalo'),

            ReportColumn::integer('empresa_id', 'Empresa (id)', indexed: true),
            ReportColumn::string('empresa', 255, 'Empresa'),
            ReportColumn::string('empresa_rnc', 32, 'RNC'),
            ReportColumn::string('empresa_estatus', 64, 'Estatus de empresa'),
            ReportColumn::string('empresa_telefono', 64),
            ReportColumn::string('sector', 64, 'Sector empresarial', indexed: true),
            ReportColumn::string('tamano', 16, 'Tamaño de la empresa'),
            ReportColumn::string('actividad', 64, 'Actividad empresarial'),
            ReportColumn::string('vinculacion', 255, 'Vinculación empresarial'),

            ReportColumn::string('direccion', 255, 'Dirección'),
            ReportColumn::string('pais', 64, 'País', indexed: true),
            ReportColumn::string('ciudad', 64, 'Ciudad', indexed: true),
            // Labelled distinctly on purpose: this is the barrio, and calling it "Sector"
            // alongside the business sector is how the two got confused in the first place.
            ReportColumn::string('distrito', 64, 'Sector geográfico (barrio)'),

            // The grain is "person in this company", and a company's people are not all
            // ejecutivos — facilitators are People too, and so is anyone imported by an earlier
            // generation. Without this the table looks like a participant list while 1,672 of
            // agency 1's rows are facilitators, and `COUNT(*)` over-reports every headcount.
            // `pa_code` is null for them because a PA is a participant's code, not a person's.
            ReportColumn::string('tipo_persona', 64, 'Tipo de persona', indexed: true),

            ReportColumn::string('estatus', 64, 'Estatus', indexed: true),
            // Deleted in SIPGO but kept here, flagged. Dropping the row instead would take the
            // person out of every historical count while their registrations stay in
            // `inscripcion` — 24 of agency 4's 798 — so nothing would ever reconcile against the
            // legacy system, which is the check someone runs at cutover. The Gestor filters
            // these out by default; a report can ask for them.
            ReportColumn::boolean('esta_borrado', 'Borrado en SIPGO', indexed: true),
            ReportColumn::string('relacion_comercial', 16, 'Relación comercial', indexed: true),
            ReportColumn::string('clasificacion', 32, 'Clasificación interna'),
            ReportColumn::string('potencialidad', 32, 'Potencialidad'),
            ReportColumn::decimal('satisfaccion', 4, 2, 'Satisfacción'),
            ReportColumn::decimal('descuento', 5, 2, 'Descuento %'),
            // Distinct from pa_code: the Gestor has two fields labelled "Código".
            ReportColumn::string('tc_code', 32, 'Código TC'),
            ReportColumn::decimal('tc_porcentaje', 5, 2),
            ReportColumn::boolean('persona_clave', 'Persona clave', indexed: true),
            ReportColumn::boolean('contacto_clave_axis', 'Contacto clave Axis'),
            ReportColumn::boolean('representante_general', 'Representante general'),
            ReportColumn::boolean('referido', 'Referido'),

            // Coalesced oficina → personal → asistente, so every consumer agrees.
            ReportColumn::string('email', 255, 'Email'),
            ReportColumn::string('email_oficina', 255),
            ReportColumn::string('email_personal', 255),
            ReportColumn::string('email_asistente', 255),
            ReportColumn::string('telefono_oficina_1', 32),
            ReportColumn::string('telefono_oficina_2', 32),
            ReportColumn::string('telefono_casa', 32),
            ReportColumn::string('celular_1', 32),
            ReportColumn::string('celular_2', 32),
            ReportColumn::string('ext_1', 16),
            ReportColumn::string('ext_2', 16),

            // Single-condition membership sets — JSON with a multi-valued index rather than
            // their own grain.
            ReportColumn::jsonArray('grupos', 'CHAR(64)', 'Grupos'),
            ReportColumn::jsonArray('temas_interes', 'CHAR(128)', 'Temas de interés'),
            ReportColumn::jsonArray('eventos_interes', 'UNSIGNED', 'Eventos de interés'),
            ReportColumn::jsonArray('programas', 'CHAR(128)', 'Programas'),

            // From the legacy audits table — the only provenance SIPGO has.
            ReportColumn::string('creado_por', 128, 'Creado por'),
            ReportColumn::datetime('creado_en', 'Creado en'),
            ReportColumn::string('modificado_por', 128, 'Modificado por'),
            ReportColumn::datetime('modificado_en', 'Modificado en'),

            // Rollups, computed at refresh so the UI never aggregates on read.
            ReportColumn::integer('total_inscripciones', 'Total inscripciones'),
            ReportColumn::integer('total_asistencias', 'Total asistencias'),
            ReportColumn::date('ultima_inscripcion', 'Última inscripción'),
            ReportColumn::boolean('tiene_cortesia', 'Tiene cortesía'),
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
        // withTrashed(): People carries a global `is_deleted = 0` scope, and a participant
        // deleted in SIPGO is imported flagged rather than skipped. The flag is a column here
        // (`esta_borrado`), not a missing row — see columns().
        $query = People::withTrashed()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId());

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        // Five rows per company, so it loads once for the whole run rather than per chunk.
        $this->peopleTypeNames = DB::connection('crm')
            ->table('people_types')
            ->where('companies_id', $company->getId())
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();

        // Generator + chunking: a full rebuild is 65k people per agency and must not
        // materialise in memory.
        foreach ($query->orderBy('id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $people = $chunk->all();
            $peopleIds = array_map(fn (People $p) => (int) $p->getId(), $people);

            $customFields = $this->customFieldsFor($peopleIds);
            $organizations = $this->organizationsFor($peopleIds);
            $addresses = $this->addressesFor($peopleIds);
            $contacts = $this->contactsFor($peopleIds);
            $tags = $this->tagsFor($peopleIds);
            $rollups = $this->rollupsFor($app, $company, $peopleIds);

            foreach ($people as $person) {
                yield $this->row(
                    $person,
                    $company,
                    $customFields[(int) $person->getId()] ?? [],
                    $organizations[(int) $person->getId()] ?? null,
                    $addresses[(int) $person->getId()] ?? null,
                    $contacts[(int) $person->getId()] ?? [],
                    $tags[(int) $person->getId()] ?? [],
                    $rollups[(int) $person->getId()] ?? [],
                );
            }
        }
    }

    /**
     * An Organization rename touches every one of its people; there is no way to know that from
     * the person. This is the fan-out the refresh service has to batch.
     *
     * @return array<class-string, callable(object): array<int, int>>
     */
    #[Override]
    public function invalidatedBy(): array
    {
        return [
            People::class => fn (People $person): array => [(int) $person->getId()],
            Organization::class => fn (Organization $organization): array => DB::connection('crm')
                ->table('organizations_peoples')
                ->where('organizations_id', $organization->getId())
                ->pluck('peoples_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /**
     * Pivot the EAV custom fields into a flat map, one query per chunk.
     *
     * This is the join the flat table exists to avoid — it runs here, once per person, on the
     * write path, instead of on every Gestor query.
     *
     * @param array<int, int> $peopleIds
     *
     * @return array<int, array<string, string>>
     */
    protected function customFieldsFor(array $peopleIds): array
    {
        // Deliberately NOT filtered by companies_id. That column records the company context of
        // whoever wrote the field, not the entity's owner — a person in company 11569 can carry
        // fields tagged 19625 if the import ran under a different current company. `entity_id`
        // is a globally unique People id and the ids were already company-scoped by the caller,
        // so scoping on it alone is both correct and unambiguous.
        $rows = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', People::class)
            ->whereIn('entity_id', $peopleIds)
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
     * @return array<int, object>
     */
    protected function organizationsFor(array $peopleIds): array
    {
        $rows = DB::connection('crm')
            ->table('organizations_peoples as op')
            ->join('organizations as o', 'o.id', '=', 'op.organizations_id')
            ->whereIn('op.peoples_id', $peopleIds)
            ->where('o.is_deleted', 0)
            ->select('op.peoples_id', 'o.id', 'o.name')
            ->orderBy('o.id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            // A person can sit in several organizations; the Gestor treats the first as theirs.
            $map[(int) $row->peoples_id] ??= $row;
        }

        $business = $this->businessAttributesFor(array_map(
            static fn (object $row): int => (int) $row->id,
            array_values($map)
        ));

        foreach ($map as $row) {
            $row->business = $business[(int) $row->id] ?? [];
        }

        return $map;
    }

    /**
     * Sector, size and activity belong to the **company**, not the person.
     *
     * The person carries a custom field literally called `sector`, and in SIPGO it is the Santo
     * Domingo neighbourhood — PIANTINI, NACO, GAZCUE. Reading it as the business sector matched
     * the company's own value on 3 of 24,301 rows, and produced confident nonsense everywhere it
     * was grouped on. The barrio is not lost: it stays in `distrito`.
     *
     * `tamano` and `actividad` had the same mistake with a louder symptom — no person carries
     * either field, so both columns were empty on all 123,813 rows.
     *
     * @param array<int, int> $organizationIds
     *
     * @return array<int, array<string, string>>
     */
    protected function businessAttributesFor(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $rows = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', Organization::class)
            ->whereIn('entity_id', array_values(array_unique($organizationIds)))
            ->whereIn('name', ['sector', 'tamano', 'actividad'])
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
     * @return array<int, object>
     */
    protected function addressesFor(array $peopleIds): array
    {
        $rows = DB::connection('crm')
            ->table('peoples_address as pa')
            ->leftJoin(DB::connection('ecosystem')->getDatabaseName() . '.countries as c', 'c.id', '=', 'pa.countries_id')
            ->whereIn('pa.peoples_id', $peopleIds)
            ->where('pa.is_deleted', 0)
            ->select('pa.peoples_id', 'pa.address', 'pa.city', 'c.name as country')
            ->orderBy('pa.id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->peoples_id] ??= $row;
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
            ->select('pc.peoples_id', 'ct.name as type', 'pc.value', 'pc.weight')
            ->orderBy('pc.weight')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $key = strtolower((string) $row->type);
            $map[(int) $row->peoples_id][$key] ??= (string) $row->value;
        }

        return $map;
    }

    /**
     * @param array<int, int> $peopleIds
     *
     * @return array<int, array<int, string>>
     */
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
     * Registration rollups, computed here so the UI never aggregates on read.
     *
     * @param array<int, int> $peopleIds
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rollupsFor(AppInterface $app, Companies $company, array $peopleIds): array
    {
        $rows = DB::connection('event')
            ->table('event_version_participants as evp')
            ->join('participants as p', 'p.id', '=', 'evp.participant_id')
            ->join('event_versions as ev', 'ev.id', '=', 'evp.event_version_id')
            ->whereIn('p.people_id', $peopleIds)
            ->where('p.apps_id', $app->getId())
            ->where('p.companies_id', $company->getId())
            ->where('evp.is_deleted', 0)
            ->groupBy('p.people_id')
            ->selectRaw('p.people_id, COUNT(*) as total, MAX(ev.start_at) as ultima')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->people_id] = [
                'total_inscripciones' => (int) $row->total,
                'ultima_inscripcion' => $row->ultima === null ? null : substr((string) $row->ultima, 0, 10),
            ];
        }

        return $map;
    }

    /**
     * Assemble one flat row.
     *
     * Business rules are resolved here, once, so the UI, the agent and every report share one
     * answer — `email` coalesces oficina → personal → asistente, `relacion_comercial` turns the
     * 0/1 prospect flag into the word the Gestor shows.
     *
     * @param array<string, string> $customFields
     * @param array<string, string> $contacts
     * @param array<int, string> $tags
     * @param array<string, mixed> $rollups
     *
     * @return array<string, mixed>
     */
    protected function row(
        People $person,
        Companies $company,
        array $customFields,
        ?object $organization,
        ?object $address,
        array $contacts,
        array $tags,
        array $rollups
    ): array {
        $cf = fn (string $name, mixed $default = null) => $customFields[$name] ?? $default;
        $isProspect = $cf('intras_is_prospect');

        return [
            'peoples_id' => (int) $person->getId(),
            'companies_id' => $company->getId(),

            'pa_code' => $cf('pa_code'),
            'legacy_id' => $cf('INTRAS_PARTICIPANT_ID') === null ? null : (int) $cf('INTRAS_PARTICIPANT_ID'),

            'nombre' => $person->firstname,
            'apellido' => $person->lastname,
            'nombre_completo' => trim($person->firstname . ' ' . $person->lastname),
            'cert_primer_nombre' => $cf('certificado_primer_nombre'),
            'cert_segundo_nombre' => $cf('certificado_segundo_nombre'),
            'cert_primer_apellido' => $cf('certificado_primer_apellido'),
            'cert_segundo_apellido' => $cf('certificado_segundo_apellido'),

            'cedula' => $cf('identification'),
            'sexo' => $cf('sexo'),
            'dob' => $person->dob,
            'dob_mes' => $cf('dob_mes') === null ? null : (int) $cf('dob_mes'),
            'dob_dia' => $cf('dob_dia') === null ? null : (int) $cf('dob_dia'),

            'posicion' => $cf('position'),
            'nivel' => $cf('nivel'),
            'area' => $cf('area'),
            'linea_tematica' => $cf('linea_tematica'),
            'departamento' => $cf('department'),
            'profesion' => $cf('profesion'),
            'tipo_regalo' => $cf('tipo_regalo'),

            'empresa_id' => $organization?->id === null ? null : (int) $organization->id,
            'empresa' => $organization?->name,
            'empresa_rnc' => $cf('empresa_rnc'),
            'empresa_estatus' => $cf('empresa_estatus'),
            'empresa_telefono' => $cf('empresa_telefono'),
            // From the company, not the person — see businessAttributesFor(). The person's own
            // `sector` custom field is the barrio and lives in `distrito` below.
            'sector' => $organization?->business['sector'] ?? null,
            'tamano' => $organization?->business['tamano'] ?? null,
            'actividad' => $organization?->business['actividad'] ?? null,
            'vinculacion' => $cf('vinculacion'),

            'direccion' => $address?->address,
            // Custom field first: the legacy name survives even when it matched no Kanvas country.
            'pais' => $cf('pais') ?? $address?->country,
            'ciudad' => $address?->city,
            'distrito' => $cf('sector_geografico') ?? $cf('sector'),

            'tipo_persona' => $this->peopleTypeNames[(int) $person->people_types_id] ?? null,

            'estatus' => $cf('estatus'),
            'esta_borrado' => (int) (bool) $person->is_deleted,
            // The 0/1 flag the Gestor renders as CLIENTE / POTENCIAL.
            'relacion_comercial' => $isProspect === null ? null : ($isProspect ? 'POTENCIAL' : 'CLIENTE'),
            'clasificacion' => $cf('intras_classification'),
            'potencialidad' => $cf('potencialidad'),
            'satisfaccion' => $cf('satisfaccion'),
            'descuento' => $cf('descuento'),
            'tc_code' => $cf('tc_code'),
            'tc_porcentaje' => $cf('tc_porcentaje'),
            'persona_clave' => $this->flag($cf('is_key_participant')),
            'contacto_clave_axis' => $this->flag($cf('contacto_clave_axis')),
            'representante_general' => $this->flag($cf('representante_general')),
            'referido' => $this->flag($cf('referido')),

            // One coalesced email so no consumer re-derives the chain.
            'email' => $contacts['email'] ?? $contacts['secondary_email'] ?? null,
            'email_oficina' => $contacts['email'] ?? null,
            'email_personal' => $contacts['secondary_email'] ?? null,
            'email_asistente' => null,
            'telefono_oficina_1' => $contacts['work_phone'] ?? null,
            'telefono_oficina_2' => null,
            'telefono_casa' => $contacts['phone'] ?? null,
            'celular_1' => $contacts['cellphone'] ?? null,
            'celular_2' => null,
            'ext_1' => $cf('intras_ext_1'),
            'ext_2' => $cf('intras_ext_2'),

            'grupos' => $tags,
            'temas_interes' => [],
            'eventos_interes' => $this->jsonField($cf('eventos_interes')),
            'programas' => [],

            'creado_por' => $cf('creado_por'),
            'creado_en' => $cf('creado_en'),
            'modificado_por' => $cf('modificado_por'),
            'modificado_en' => $cf('modificado_en'),

            'total_inscripciones' => $rollups['total_inscripciones'] ?? 0,
            'total_asistencias' => $rollups['total_inscripciones'] ?? 0,
            'ultima_inscripcion' => $rollups['ultima_inscripcion'] ?? null,
            'tiene_cortesia' => 0,
        ];
    }

    /**
     * Custom fields come back as strings, so "0" must stay false rather than becoming truthy.
     */
    protected function flag(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array((string) $value, ['1', 'true'], true) ? 1 : 0;
    }

    /**
     * @return array<int, mixed>
     */
    protected function jsonField(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
