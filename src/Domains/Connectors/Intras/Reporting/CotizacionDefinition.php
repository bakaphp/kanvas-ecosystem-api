<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Override;

/**
 * One row per quote (propuesta) a company requested.
 *
 * The only entity that was imported but never flattened, which is why two of the questions the
 * business actually asks had nowhere to run: "¿Qué empresas han solicitado propuestas in-house
 * del facilitador X?" and "¿de temas relacionados a X?". Both are membership tests over
 * `quotes_facilitators` / `quotes_events` / `quotes_keywords` — 5,960, 3,200 and 4,775 legacy
 * rows that only reached Kanvas once the lead importer learned to read them.
 *
 * Those three are JSON arrays with multi-valued indexes rather than their own grain: a quote is
 * only ever filtered by *whether* it involves a facilitator or theme, never by a correlated pair
 * of conditions on the same one. The same rule that keeps `grupos` off `ejecutivo`.
 *
 * It also carries the in-house side of the EMPRESA classification sheet — approved proposal
 * count and accumulated investment — which cannot be derived from `inscripcion` because a quote
 * that was never won produced no registrations at all.
 */
class CotizacionDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    /**
     * The legacy stage slugs that mean the proposal was accepted. `LeadMapper` maps GANADA and
     * GANADA PLAN onto these, and the classification sheet counts exactly those as "aprobadas".
     */
    private const array WON_STAGES = ['won', 'won-plan'];

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'cotizacion';
    }

    #[Override]
    public function label(): string
    {
        return 'Cotización';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::QUOTE;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'cotizacion_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('legacy_id', 'Cotización (id SIPGO)', indexed: true),
            ReportColumn::string('numero', 32, 'Número', indexed: true),
            ReportColumn::string('titulo', 255, 'Título'),

            ReportColumn::integer('empresa_id', 'Empresa (id)', indexed: true),
            ReportColumn::string('empresa', 255, 'Empresa', indexed: true),
            ReportColumn::string('sector', 64, 'Sector', indexed: true),

            ReportColumn::integer('peoples_id', 'Ejecutivo (id)', indexed: true),
            ReportColumn::string('pa_code', 16, 'PA'),
            ReportColumn::string('ejecutivo', 256, 'Ejecutivo'),

            ReportColumn::string('etapa', 64, 'Etapa', indexed: true),
            ReportColumn::boolean('es_ganada', 'Aprobada', indexed: true),

            ReportColumn::string('area', 64, 'Área', indexed: true),
            ReportColumn::string('potencialidad', 32, 'Potencialidad'),
            ReportColumn::string('clasificacion', 32, 'Clasificación'),

            ReportColumn::decimal('monto', 14, 2, 'Monto'),
            ReportColumn::decimal('presupuesto_min', 14, 2, 'Presupuesto mínimo'),
            ReportColumn::decimal('presupuesto_max', 14, 2, 'Presupuesto máximo'),
            ReportColumn::integer('total_participantes', 'Participantes estimados'),

            ReportColumn::date('fecha_solicitud', 'Fecha de solicitud', indexed: true),
            ReportColumn::date('fecha_envio', 'Fecha de envío'),

            ReportColumn::string('idioma', 64, 'Idioma'),
            ReportColumn::string('lugar', 128, 'Lugar'),
            ReportColumn::string('sala', 128, 'Sala'),
            ReportColumn::string('tipo_montaje', 64, 'Tipo de montaje'),
            ReportColumn::string('rango_edad', 64, 'Rango de edad'),

            ReportColumn::boolean('en_sede_cliente', 'En sede del cliente'),
            ReportColumn::boolean('multiples_lugares', 'Múltiples lugares'),
            ReportColumn::boolean('tiene_graduacion', 'Con graduación'),
            ReportColumn::boolean('tiene_traduccion', 'Con traducción'),

            ReportColumn::text('objetivos'),
            ReportColumn::text('problematica'),

            // Membership sets, indexed multi-valued so `MEMBER OF` stays index-backed.
            ReportColumn::jsonArray('facilitadores', 'CHAR(128)', 'Facilitadores solicitados'),
            ReportColumn::jsonArray('eventos_solicitados', 'CHAR(255)', 'Eventos solicitados'),
            ReportColumn::jsonArray('temas', 'CHAR(128)', 'Temas'),
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
        // Plain query: Lead carries no global soft-delete scope, and a quote deleted in SIPGO is
        // never imported in the first place, so there is nothing to include or exclude here.
        $query = Lead::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId());

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        foreach ($query->orderBy('id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $leads = $chunk->all();
            $leadIds = array_map(fn (Lead $lead): int => (int) $lead->getId(), $leads);

            $fields = $this->customFields($leadIds);
            $stages = $this->stageNames($leads);
            $organizations = $this->flatOrganizations($leads);
            $people = $this->flatPeople($leads);

            foreach ($leads as $lead) {
                $leadId = (int) $lead->getId();
                $cf = fn (string $key): ?string => $fields[$leadId][$key] ?? null;

                // A lead with no legacy id is not a SIPGO quote — the pipeline is shared with
                // whatever else the tenant creates, and those rows are not proposals.
                $legacyId = $cf('INTRAS_QUOTE_ID');

                if ($legacyId === null) {
                    continue;
                }

                $organization = $organizations[(int) $lead->organization_id] ?? null;
                $person = $people[(int) $lead->people_id] ?? null;
                $stage = $stages[(int) $lead->pipeline_stage_id] ?? null;

                yield [
                    'cotizacion_id' => $leadId,
                    'companies_id' => $company->getId(),

                    'legacy_id' => (int) $legacyId,
                    'numero' => $cf('numero'),
                    'titulo' => $lead->title,

                    'empresa_id' => $organization?->organizations_id,
                    'empresa' => $organization?->nombre,
                    'sector' => $organization?->sector,

                    'peoples_id' => $person?->peoples_id,
                    'pa_code' => $person?->pa_code,
                    'ejecutivo' => $person?->nombre_completo,

                    'etapa' => $stage,
                    'es_ganada' => in_array((string) $stage, self::WON_STAGES, true) ? 1 : 0,

                    'area' => $cf('area'),
                    'potencialidad' => $cf('potencialidad'),
                    'clasificacion' => $cf('classification'),

                    'monto' => $this->decimal($cf('quote_amount')),
                    'presupuesto_min' => $this->decimal($cf('presupuesto_min')),
                    'presupuesto_max' => $this->decimal($cf('presupuesto_max')),
                    'total_participantes' => $cf('total_participants') === null
                        ? null
                        : (int) $cf('total_participants'),

                    'fecha_solicitud' => $this->date($cf('requested_date')),
                    'fecha_envio' => $this->date($cf('sent_date')),

                    'idioma' => $cf('idioma'),
                    'lugar' => $cf('lugar'),
                    'sala' => $cf('sala'),
                    'tipo_montaje' => $cf('tipo_montaje'),
                    'rango_edad' => $cf('rango_edad'),

                    'en_sede_cliente' => $this->flag($cf('en_sede_cliente')),
                    'multiples_lugares' => $this->flag($cf('multiples_lugares')),
                    'tiene_graduacion' => $this->flag($cf('tiene_graduacion')),
                    'tiene_traduccion' => $this->flag($cf('tiene_traduccion')),

                    'objetivos' => $cf('info_objectives'),
                    'problematica' => $cf('info_issues'),

                    'facilitadores' => $this->jsonArray($cf('facilitadores')),
                    'eventos_solicitados' => $this->jsonArray($cf('eventos_solicitados')),
                    'temas' => $this->jsonArray($cf('temas')),
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
            Lead::class => fn (Lead $lead): array => [(int) $lead->getId()],
        ];
    }

    /**
     * @param array<int, int> $leadIds
     *
     * @return array<int, array<string, string>>
     */
    protected function customFields(array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        $pivoted = [];

        // Scoped on entity_id alone: `apps_custom_fields.companies_id` records whichever company
        // wrote the row, not the one that owns the lead, so filtering on it drops everything.
        $rows = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', Lead::class)
            ->whereIn('entity_id', $leadIds)
            ->where('is_deleted', 0)
            ->select('entity_id', 'name', 'value')
            ->get();

        foreach ($rows as $row) {
            $pivoted[(int) $row->entity_id][$row->name] = $row->value;
        }

        return $pivoted;
    }

    /**
     * @param array<int, Lead> $leads
     *
     * @return array<int, string>
     */
    protected function stageNames(array $leads): array
    {
        $stageIds = array_values(array_unique(array_filter(
            array_map(fn (Lead $lead): int => (int) $lead->pipeline_stage_id, $leads)
        )));

        if ($stageIds === []) {
            return [];
        }

        return DB::connection('crm')
            ->table('pipelines_stages')
            ->whereIn('id', $stageIds)
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * Read from the already-flattened `empresa` table rather than re-pivoting the organization's
     * custom fields — the provider orders `empresa` before `cotizacion` for exactly this.
     *
     * @param array<int, Lead> $leads
     *
     * @return array<int, object>
     */
    protected function flatOrganizations(array $leads): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (Lead $lead): int => (int) $lead->organization_id, $leads)
        )));

        return $this->flatRows('empresa', 'organizations_id', $ids);
    }

    /**
     * @param array<int, Lead> $leads
     *
     * @return array<int, object>
     */
    protected function flatPeople(array $leads): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (Lead $lead): int => (int) $lead->people_id, $leads)
        )));

        return $this->flatRows('ejecutivo', 'peoples_id', $ids);
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

    protected function decimal(?string $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    protected function date(?string $value): ?string
    {
        return $value === null || $value === '' ? null : substr($value, 0, 10);
    }

    /**
     * Custom fields come back as strings, so a plain cast makes "0" truthy and every boolean
     * filter matches everything.
     */
    protected function flag(?string $value): int
    {
        return in_array($value, ['1', 'true', 'TRUE'], true) ? 1 : 0;
    }

    /**
     * @return list<string>
     */
    protected function jsonArray(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
