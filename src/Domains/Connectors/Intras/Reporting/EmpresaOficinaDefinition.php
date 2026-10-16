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
 * One row per company office.
 *
 * País, ciudad and distrito are filtered together and have to hold on the *same* office: a
 * company with a Santo Domingo office and a Santiago office is not "in Santiago, Piantini".
 * `empresa` carries only the first office, which is enough to display and wrong to filter on.
 */
class EmpresaOficinaDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'empresa_oficina';
    }

    #[Override]
    public function label(): string
    {
        return 'Oficina de empresa';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::COMPANY_OFFICE;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'oficina_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('organizations_id', 'Empresa (id)', indexed: true),
            ReportColumn::string('empresa', 255, 'Empresa', indexed: true),
            ReportColumn::string('sector', 64, 'Sector'),

            ReportColumn::string('direccion', 255, 'Dirección'),
            ReportColumn::string('pais', 64, 'País', indexed: true),
            ReportColumn::string('ciudad', 64, 'Ciudad', indexed: true),
            ReportColumn::string('distrito', 64, 'Sector', indexed: true),
            ReportColumn::string('zip', 16),
            ReportColumn::boolean('es_principal', 'Oficina principal'),
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
        $query = DB::connection('crm')
            ->table('organizations_address as oa')
            ->join('organizations as o', 'o.id', '=', 'oa.organizations_id')
            ->leftJoin(
                DB::connection('ecosystem')->getDatabaseName() . '.countries as c',
                'c.id',
                '=',
                'oa.countries_id'
            )
            ->where('o.apps_id', $app->getId())
            ->where('o.companies_id', $company->getId())
            ->where('o.is_deleted', 0)
            ->where('oa.is_deleted', 0)
            ->select(
                'oa.id',
                'oa.organizations_id',
                'oa.address',
                'oa.city',
                'oa.state',
                'oa.zip',
                'oa.is_default',
                'c.name as country',
                'o.name as empresa',
            );

        if ($ids !== null) {
            $query->whereIn('oa.id', $ids);
        }

        foreach ($query->orderBy('oa.id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk->all();
            $organizationIds = array_values(array_unique(array_map(fn ($r) => (int) $r->organizations_id, $rows)));
            $organizations = $this->flatOrganizations($organizationIds);

            foreach ($rows as $row) {
                $organization = $organizations[(int) $row->organizations_id] ?? null;

                yield [
                    'oficina_id' => (int) $row->id,
                    'companies_id' => $company->getId(),

                    'organizations_id' => (int) $row->organizations_id,
                    'empresa' => $row->empresa,
                    'sector' => $organization?->sector,

                    'direccion' => $row->address,
                    // The organization's stored country name wins when the FK never resolved —
                    // "REPUBLICA DOMINICANA" is not in the Kanvas catalog under that spelling.
                    'pais' => $row->country ?? $organization?->pais,
                    'ciudad' => $row->city,
                    'distrito' => $row->state ?? $organization?->sector_geografico,
                    'zip' => $row->zip,
                    'es_principal' => empty($row->is_default) ? 0 : 1,
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
            Organization::class => fn (Organization $organization): array => DB::connection('crm')
                ->table('organizations_address')
                ->where('organizations_id', $organization->getId())
                ->where('is_deleted', 0)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /**
     * @param array<int, int> $organizationIds
     *
     * @return array<int, object>
     */
    protected function flatOrganizations(array $organizationIds): array
    {
        $table = sprintf('rpt_empresa_app%d', $this->appId);
        $connection = DB::connection('reporting');

        if ($organizationIds === [] || ! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $map = [];

        $rows = $connection->table($table)
            ->whereIn('organizations_id', $organizationIds)
            ->select('organizations_id', 'sector', 'pais', 'sector_geografico')
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->organizations_id] = $row;
        }

        return $map;
    }
}
