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
 * One row per plan a company holds — the Empresas tab's seven-condition cluster.
 *
 * Tier, status, expiry and remaining tickets all have to hold on the *same* plan. A company with
 * an expired Plan A and a full Plan B satisfies "ACTIVO" and "Plan A" separately but neither
 * together, which is the same defect `inscripcion` exists to avoid.
 *
 * The source is the `planes` JSON on the Organization, so the flattened grain is one array
 * element per row. The legacy "ACTIVO" — expiry in the future AND tickets left — is recomputed
 * here rather than in the Vue layer, so every consumer agrees.
 */
class EmpresaPlanDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'empresa_plan';
    }

    #[Override]
    public function label(): string
    {
        return 'Plan de empresa';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::COMPANY_PLAN;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'plan_row_id';
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

            ReportColumn::string('plan', 128, 'Plan', indexed: true),
            ReportColumn::string('tier', 128, 'Tipo de plan', indexed: true),

            ReportColumn::integer('cupos', 'Cupos'),
            ReportColumn::integer('usados', 'Cupos usados'),
            ReportColumn::integer('disponibles', 'Cupos disponibles', indexed: true),

            ReportColumn::date('fecha_emision', 'Fecha de emisión'),
            ReportColumn::date('fecha_expiracion', 'Fecha de expiración', indexed: true),

            // The legacy "ACTIVO": not expired AND tickets remaining. Recomputed here so the
            // Gestor, the reports and the agent share one answer.
            ReportColumn::string('estado', 16, 'Estatus de uso', indexed: true),
            ReportColumn::boolean('consumido', 'Consumido'),
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
        $query = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', Organization::class)
            ->where('name', 'planes')
            ->where('is_deleted', 0)
            ->select('entity_id', 'value');

        foreach ($query->orderBy('entity_id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk->all();
            $organizationIds = array_map(fn ($r) => (int) $r->entity_id, $rows);
            $organizations = $this->flatOrganizations($organizationIds);
            $today = date('Y-m-d');

            foreach ($rows as $row) {
                $organizationId = (int) $row->entity_id;
                $organization = $organizations[$organizationId] ?? null;

                // Only this company's organizations; the custom-field row itself carries the
                // writer's company, not the owner's.
                if ($organization === null) {
                    continue;
                }

                foreach ($this->decodePlans($row->value) as $index => $plan) {
                    // Synthetic but stable: the same organization and array position always
                    // produce the same key, so a refresh updates rather than duplicates.
                    $planRowId = ($organizationId * 1000) + $index;

                    if ($ids !== null && ! in_array($planRowId, $ids, true)) {
                        continue;
                    }

                    $cupos = (int) ($plan['tickets'] ?? 0);
                    $usados = (int) ($plan['used'] ?? 0);
                    $disponibles = max(0, $cupos - $usados);
                    $expira = $plan['expires'] ?? null;
                    $vencido = $expira !== null && $expira < $today;

                    yield [
                        'plan_row_id' => $planRowId,
                        'companies_id' => $company->getId(),

                        'organizations_id' => $organizationId,
                        'empresa' => $organization->nombre,
                        'sector' => $organization->sector,

                        'plan' => $plan['plan'] ?? null,
                        'tier' => $plan['tier'] ?? null,

                        'cupos' => $cupos,
                        'usados' => $usados,
                        'disponibles' => $disponibles,

                        'fecha_emision' => $plan['issued'] ?? null,
                        'fecha_expiracion' => $expira,

                        'estado' => match (true) {
                            ! empty($plan['consumed']) => 'USADO',
                            $vencido => 'INACTIVO',
                            $disponibles > 0 => 'ACTIVO',
                            default => 'INACTIVO',
                        },
                        'consumido' => empty($plan['consumed']) ? 0 : 1,
                    ];
                }
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
            // Row ids are derived from the organization id, so the whole block is rewritten when
            // the organization's plans change.
            Organization::class => fn (Organization $organization): array => range(
                (int) $organization->getId() * 1000,
                ((int) $organization->getId() * 1000) + 49
            ),
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
            ->select('organizations_id', 'nombre', 'sector')
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->organizations_id] = $row;
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function decodePlans(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
