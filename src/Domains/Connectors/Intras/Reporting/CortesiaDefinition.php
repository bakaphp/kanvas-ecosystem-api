<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Override;

/**
 * The Gestor's Cortesía/Intercambios tab, flattened.
 *
 * One row per per-participant pass. The company-level *pools* are a different shape — a quota of
 * N rather than one row per person — and live on the Organization as JSON, flattened separately.
 *
 * Kanvas has no status column on a pass: `used_date` being set is what makes it consumed, and
 * expiry is a date comparison. Both are resolved here into `estado` so the tab and the agent
 * cannot disagree about what "vigente" means.
 */
class CortesiaDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 500;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'cortesia';
    }

    #[Override]
    public function label(): string
    {
        return 'Cortesía';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::PASS;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'pass_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('legacy_id'),
            ReportColumn::string('codigo', 64, 'Código', indexed: true),

            ReportColumn::integer('peoples_id', 'Ejecutivo (id)', indexed: true),
            ReportColumn::string('pa_code', 16, 'PA', indexed: true),
            ReportColumn::string('ejecutivo', 256, 'Ejecutivo'),
            ReportColumn::integer('empresa_id', 'Empresa (id)', indexed: true),
            ReportColumn::string('empresa', 255, 'Empresa'),

            ReportColumn::integer('version_id', 'Versión (id)'),
            ReportColumn::string('evento', 255, 'Evento', indexed: true),
            ReportColumn::string('version', 255, 'Versión'),

            ReportColumn::string('motivo', 128, 'Motivo', indexed: true),
            ReportColumn::string('tipo', 32, 'Tipo', indexed: true),

            ReportColumn::date('fecha_emision', 'Fecha de emisión', indexed: true),
            ReportColumn::date('fecha_expiracion', 'Fecha de expiración', indexed: true),
            ReportColumn::date('fecha_uso', 'Fecha de uso'),

            // Resolved once: Kanvas has no status column, so "vigente" would otherwise be
            // re-derived — differently — by every caller.
            ReportColumn::string('estado', 16, 'Estado', indexed: true),
            ReportColumn::boolean('vigente', 'Cupo vigente', indexed: true),
            ReportColumn::text('comentario'),
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
            ->table('participant_passes as pp')
            ->leftJoin('participant_pass_motives as m', 'm.id', '=', 'pp.participant_pass_motive_id')
            ->leftJoin('participants as pt', 'pt.id', '=', 'pp.participant_id')
            ->leftJoin('event_versions as ev', 'ev.id', '=', 'pp.event_version_id')
            ->leftJoin('events as e', 'e.id', '=', 'pp.event_id')
            ->where('pp.apps_id', $app->getId())
            ->where('pp.companies_id', $company->getId())
            ->where('pp.is_deleted', 0)
            ->select(
                'pp.id',
                'pp.code',
                'pp.issue_date',
                'pp.expiration_date',
                'pp.used_date',
                'pp.payload',
                'pt.people_id',
                'ev.id as version_id',
                'ev.name as version',
                'e.name as evento',
                'm.name as motivo',
            );

        if ($ids !== null) {
            $query->whereIn('pp.id', $ids);
        }

        $today = date('Y-m-d');

        foreach ($query->orderBy('pp.id')->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk->all();
            $peopleIds = array_values(array_unique(array_filter(array_map(
                fn ($r) => $r->people_id === null ? null : (int) $r->people_id,
                $rows
            ))));

            $people = $this->flatPeople($peopleIds);
            $passFields = $this->customFieldsFor(array_map(fn ($r) => (int) $r->id, $rows));

            foreach ($rows as $row) {
                $payload = $this->decodePayload($row->payload ?? null);
                $person = $people[(int) ($row->people_id ?? 0)] ?? null;
                $expira = $this->dateOnly($row->expiration_date);
                $usada = $this->dateOnly($row->used_date);

                $estado = match (true) {
                    $usada !== null => 'USADA',
                    $expira !== null && $expira < $today => 'EXPIRADA',
                    default => 'VIGENTE',
                };

                $fields = $passFields[(int) $row->id] ?? [];

                yield [
                    'pass_id' => (int) $row->id,
                    'companies_id' => $company->getId(),

                    'legacy_id' => isset($fields['INTRAS_PASS_ID']) ? (int) $fields['INTRAS_PASS_ID'] : null,
                    'codigo' => $row->code,

                    'peoples_id' => $row->people_id === null ? null : (int) $row->people_id,
                    'pa_code' => $person?->pa_code,
                    'ejecutivo' => $person?->nombre_completo,
                    'empresa_id' => $person?->empresa_id,
                    'empresa' => $person?->empresa,

                    'version_id' => $row->version_id === null ? null : (int) $row->version_id,
                    'evento' => $row->evento,
                    'version' => $row->version,

                    'motivo' => $row->motivo,
                    // The legacy pair is_exchange / is_credit, which are mutually exclusive in
                    // practice; the tab filters on one word.
                    'tipo' => match (true) {
                        ! empty($payload['es_intercambio']) => 'INTERCAMBIO',
                        ! empty($payload['es_credito']) => 'CREDITO',
                        default => 'CORTESIA',
                    },

                    'fecha_emision' => $this->dateOnly($row->issue_date),
                    'fecha_expiracion' => $expira,
                    'fecha_uso' => $usada,

                    'estado' => $estado,
                    'vigente' => $estado === 'VIGENTE' ? 1 : 0,
                    'comentario' => $payload['comentario'] ?? null,
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
            ParticipantPass::class => fn (ParticipantPass $pass): array => [(int) $pass->getId()],
        ];
    }

    /**
     * @param array<int, int> $peopleIds
     *
     * @return array<int, object>
     */
    protected function flatPeople(array $peopleIds): array
    {
        if ($peopleIds === []) {
            return [];
        }

        $table = sprintf('rpt_ejecutivo_app%d', $this->appId);
        $connection = DB::connection('reporting');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $map = [];

        $rows = $connection->table($table)
            ->whereIn('peoples_id', $peopleIds)
            ->select('peoples_id', 'pa_code', 'nombre_completo', 'empresa_id', 'empresa')
            ->get();

        foreach ($rows as $row) {
            $map[(int) $row->peoples_id] = $row;
        }

        return $map;
    }

    /**
     * @param array<int, int> $passIds
     *
     * @return array<int, array<string, string>>
     */
    protected function customFieldsFor(array $passIds): array
    {
        if ($passIds === []) {
            return [];
        }

        $rows = DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', ParticipantPass::class)
            ->whereIn('entity_id', $passIds)
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
     * @return array<string, mixed>
     */
    protected function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function dateOnly(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : substr($value, 0, 10);
    }
}
