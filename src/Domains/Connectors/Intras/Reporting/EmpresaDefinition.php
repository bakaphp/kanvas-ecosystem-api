<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Override;

/**
 * The Gestor's Empresas tab, flattened.
 *
 * Company-level filters answer from here. The plan cluster — seven conditions that must hold on
 * the *same* plan — lives in `empresa_plan`, and the país/ciudad/distrito trio in
 * `empresa_oficina`, for the same reason `inscripcion` exists: correlated conditions need a row
 * per child, not an array.
 *
 * Rollups are precomputed so the tab never aggregates on read.
 */
class EmpresaDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'empresa';
    }

    #[Override]
    public function label(): string
    {
        return 'Empresa';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::COMPANY;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'organizations_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('legacy_id'),
            ReportColumn::string('nombre', 255, 'Empresa', indexed: true),
            ReportColumn::string('rnc', 32, 'RNC', indexed: true),

            ReportColumn::string('estatus', 64, 'Estatus', indexed: true),
            ReportColumn::string('tipo', 64, 'Tipo', indexed: true),
            ReportColumn::string('sector', 64, 'Sector empresarial', indexed: true),
            ReportColumn::string('actividad', 64, 'Actividad empresarial', indexed: true),
            ReportColumn::string('tamano', 16, 'Tamaño', indexed: true),
            ReportColumn::string('vinculacion', 255, 'Vinculación empresarial'),

            ReportColumn::string('clasificacion', 32, 'Clasificación interna'),
            ReportColumn::string('clasificacion_abierta', 32),
            ReportColumn::string('potencialidad', 32, 'Potencialidad'),
            // is_prospect rendered as the word the Gestor shows, resolved once.
            ReportColumn::string('relacion_comercial', 16, 'Relación comercial', indexed: true),
            ReportColumn::boolean('es_suplidor', 'Es suplidor'),
            ReportColumn::decimal('satisfaccion', 4, 2, 'Satisfacción'),

            ReportColumn::integer('total_empleados', 'Total de empleados'),
            ReportColumn::integer('cotizaciones_total', 'Cotizaciones'),
            ReportColumn::integer('cotizaciones_ganadas', 'Cotizaciones ganadas'),
            ReportColumn::decimal('rango_inversion', 14, 2, 'Rango de inversión'),

            ReportColumn::string('telefono', 64, 'Teléfono'),
            ReportColumn::string('email', 255, 'Email'),
            ReportColumn::string('direccion', 255, 'Dirección'),
            ReportColumn::string('pais', 64, 'País', indexed: true),
            ReportColumn::string('ciudad', 64, 'Ciudad', indexed: true),
            ReportColumn::string('sector_geografico', 64, 'Sector'),

            ReportColumn::string('creado_por', 128, 'Creado por'),
            ReportColumn::datetime('creado_en', 'Creado en'),
            ReportColumn::string('modificado_por', 128, 'Modificado por'),
            ReportColumn::datetime('modificado_en', 'Modificado en'),

            // Precomputed so the tab never aggregates on read.
            ReportColumn::integer('total_ejecutivos', 'Ejecutivos'),
            ReportColumn::integer('total_inscripciones', 'Inscripciones'),
            ReportColumn::date('ultima_inscripcion', 'Última inscripción'),
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
        $query = Organization::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        foreach ($query->orderBy('id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $organizations = $chunk->all();
            $organizationIds = array_map(fn (Organization $o) => (int) $o->getId(), $organizations);

            $customFields = $this->customFieldsFor($organizationIds);
            $addresses = $this->addressesFor($organizationIds);
            $rollups = $this->rollupsFor($app, $company, $organizationIds);

            foreach ($organizations as $organization) {
                $id = (int) $organization->getId();

                yield $this->row(
                    $organization,
                    $company,
                    $customFields[$id] ?? [],
                    $addresses[$id] ?? null,
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
            Organization::class => fn (Organization $organization): array => [(int) $organization->getId()],
        ];
    }

    /**
     * Not company-scoped: `apps_custom_fields.companies_id` records the writer's company context,
     * not the entity's owner. Entity ids are globally unique and already scoped by the caller.
     *
     * @param array<int, int> $organizationIds
     *
     * @return array<int, array<string, string>>
     */
    protected function customFieldsFor(array $organizationIds): array
    {
        $rows = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', Organization::class)
            ->whereIn('entity_id', $organizationIds)
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
     * @param array<int, int> $organizationIds
     *
     * @return array<int, object>
     */
    protected function addressesFor(array $organizationIds): array
    {
        $rows = DB::connection('crm')
            ->table('organizations_address as oa')
            ->leftJoin(
                DB::connection('ecosystem')->getDatabaseName() . '.countries as c',
                'c.id',
                '=',
                'oa.countries_id'
            )
            ->whereIn('oa.organizations_id', $organizationIds)
            ->where('oa.is_deleted', 0)
            ->select('oa.organizations_id', 'oa.address', 'oa.city', 'c.name as country')
            ->orderBy('oa.id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->organizations_id] ??= $row;
        }

        return $map;
    }

    /**
     * Headcount and attendance per company, computed here rather than on every read.
     *
     * @param array<int, int> $organizationIds
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rollupsFor(AppInterface $app, Companies $company, array $organizationIds): array
    {
        $map = [];

        $people = DB::connection('crm')
            ->table('organizations_peoples as op')
            ->join('peoples as p', 'p.id', '=', 'op.peoples_id')
            ->whereIn('op.organizations_id', $organizationIds)
            ->where('p.is_deleted', 0)
            ->groupBy('op.organizations_id')
            ->selectRaw('op.organizations_id, COUNT(DISTINCT p.id) as total')
            ->get();

        foreach ($people as $row) {
            $map[(int) $row->organizations_id]['total_ejecutivos'] = (int) $row->total;
        }

        // Registrations reach the company through the person, so this counts attendance by the
        // people currently linked to the organization.
        $registrations = DB::connection('event')
            ->table('event_version_participants as evp')
            ->join('participants as pt', 'pt.id', '=', 'evp.participant_id')
            ->join('event_versions as ev', 'ev.id', '=', 'evp.event_version_id')
            ->join(
                DB::connection('crm')->getDatabaseName() . '.organizations_peoples as op',
                'op.peoples_id',
                '=',
                'pt.people_id'
            )
            ->whereIn('op.organizations_id', $organizationIds)
            ->where('evp.is_deleted', 0)
            ->where('pt.apps_id', $app->getId())
            ->where('pt.companies_id', $company->getId())
            ->groupBy('op.organizations_id')
            ->selectRaw('op.organizations_id, COUNT(*) as total, MAX(ev.start_at) as ultima')
            ->get();

        foreach ($registrations as $row) {
            $map[(int) $row->organizations_id]['total_inscripciones'] = (int) $row->total;
            $map[(int) $row->organizations_id]['ultima_inscripcion'] = $row->ultima === null
                ? null
                : substr((string) $row->ultima, 0, 10);
        }

        return $map;
    }

    /**
     * @param array<string, string> $customFields
     * @param array<string, mixed> $rollups
     *
     * @return array<string, mixed>
     */
    protected function row(
        Organization $organization,
        Companies $company,
        array $customFields,
        ?object $address,
        array $rollups
    ): array {
        $cf = fn (string $name, mixed $default = null) => $customFields[$name] ?? $default;
        $isProspect = $cf('intras_is_prospect');

        return [
            'organizations_id' => (int) $organization->getId(),
            'companies_id' => $company->getId(),

            'legacy_id' => $cf('INTRAS_COMPANY_ID') === null ? null : (int) $cf('INTRAS_COMPANY_ID'),
            'nombre' => $organization->name,
            'rnc' => $cf('rnc'),

            'estatus' => $cf('estatus'),
            'tipo' => $cf('tipo'),
            'sector' => $cf('sector'),
            'actividad' => $cf('actividad'),
            'tamano' => $cf('tamano'),
            'vinculacion' => $cf('vinculacion'),

            'clasificacion' => $cf('classification'),
            'clasificacion_abierta' => $cf('clasificacion_abierta'),
            'potencialidad' => $cf('potencialidad'),
            'relacion_comercial' => $isProspect === null ? null : ($isProspect ? 'POTENCIAL' : 'CLIENTE'),
            'es_suplidor' => $this->flag($cf('es_suplidor')),
            'satisfaccion' => $cf('satisfaccion'),

            'total_empleados' => $cf('total_employees') ?? $organization->total_employees,
            'cotizaciones_total' => $cf('cotizaciones_total'),
            'cotizaciones_ganadas' => $cf('cotizaciones_ganadas'),
            'rango_inversion' => $cf('rango_inversion'),

            'telefono' => $cf('telefono') ?? $organization->phone,
            'email' => $organization->email,
            'direccion' => $address?->address ?? $organization->address,
            // Custom field first: the legacy country name survives even when it matched no
            // Kanvas country, which is the case for "REPUBLICA DOMINICANA".
            'pais' => $cf('pais') ?? $address?->country,
            'ciudad' => $address?->city ?? $organization->city,
            'sector_geografico' => $cf('sector_geografico'),

            'creado_por' => $cf('creado_por'),
            'creado_en' => $cf('creado_en'),
            'modificado_por' => $cf('modificado_por'),
            'modificado_en' => $cf('modificado_en'),

            'total_ejecutivos' => $rollups['total_ejecutivos'] ?? 0,
            'total_inscripciones' => $rollups['total_inscripciones'] ?? 0,
            'ultima_inscripcion' => $rollups['ultima_inscripcion'] ?? null,
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
}
