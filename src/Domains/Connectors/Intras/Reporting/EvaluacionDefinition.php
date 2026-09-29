<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Reporting;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\RefreshableReportInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\Enums\ReportGrainEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Guild\Customers\Models\People;
use Override;

/**
 * Evaluation answers, flattened. One row per answer on a filled form.
 *
 * **This definition reads the legacy database directly, not Kanvas** — the only one that does.
 * Evaluations have no Kanvas entity and, with no new tables being added, the flat table is their
 * home rather than a projection of one. That is defensible precisely here: a filled evaluation is
 * read-only history that nobody edits in Kanvas, so there is no source of truth to diverge from.
 * The consequence is that it stops refreshing when SIPGO is switched off, at which point the flat
 * table simply *is* the archive.
 *
 * Why it is worth flattening at all: 201k answers sit four joins deep
 * (answers → filled_forms → questions → forms), and the legacy `ReportData` runs six round trips
 * per question for the histogram plus four more for yes/no. That is the N+1 this whole layer
 * exists to remove, and it is the reason nobody can run the analysis today — the one report that
 * consumes this data has been broken in SIPGO for years while 20k forms kept arriving.
 *
 * Flattening also makes `satisfaccion` computable instead of an imported scalar nobody can
 * recalculate.
 */
class EvaluacionDefinition implements RefreshableReportInterface
{
    private const int CHUNK = 2000;

    public function __construct(private readonly int $appId = 0)
    {
    }

    #[Override]
    public function model(): string
    {
        return 'evaluacion';
    }

    #[Override]
    public function label(): string
    {
        return 'Evaluación';
    }

    #[Override]
    public function grain(): ReportGrainEnum
    {
        return ReportGrainEnum::EVALUATION_ANSWER;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'respuesta_id';
    }

    /**
     * @return array<int, ReportColumn>
     */
    #[Override]
    public function columns(): array
    {
        return [
            ReportColumn::integer('formulario_id', 'Formulario llenado (id)', indexed: true),
            ReportColumn::string('formulario', 255, 'Formulario', indexed: true),
            ReportColumn::string('evalua_a', 64, 'Evalúa a'),
            ReportColumn::string('evaluado_por', 64, 'Evaluado por'),

            // Who filled it, mapped to Kanvas so this joins to the other flat tables.
            ReportColumn::integer('peoples_id', 'Ejecutivo (id)', indexed: true),
            ReportColumn::string('pa_code', 16, 'PA', indexed: true),
            ReportColumn::string('ejecutivo', 256, 'Ejecutivo'),
            ReportColumn::string('empresa', 255, 'Empresa', indexed: true),

            // What was evaluated.
            ReportColumn::integer('version_id', 'Versión (id)', indexed: true),
            ReportColumn::string('evento', 255, 'Evento', indexed: true),
            ReportColumn::string('version', 255, 'Versión'),
            ReportColumn::string('tipo', 64, 'Tipo'),
            ReportColumn::string('clase', 64, 'Clase'),
            ReportColumn::date('fecha_evento', 'Fecha del evento', indexed: true),

            ReportColumn::integer('pregunta_id', 'Pregunta (id)'),
            ReportColumn::string('pregunta', 512, 'Pregunta', indexed: true),
            ReportColumn::string('tipo_pregunta', 64, 'Tipo de pregunta', indexed: true),
            ReportColumn::integer('orden', 'Orden'),

            ReportColumn::text('respuesta'),
            // Only set when the answer is numeric. Averaging `respuesta` directly would silently
            // treat free text as 0, which is how a satisfaction score ends up wrong.
            ReportColumn::decimal('puntuacion', 6, 2, 'Puntuación'),
            ReportColumn::boolean('es_numerica', 'Respuesta numérica', indexed: true),
            // The legacy flag the old report never honoured — comments meant to stay out of
            // reporting. Kept so a caller can exclude them deliberately.
            ReportColumn::boolean('excluir_de_reportes', 'Excluir de reportes', indexed: true),

            ReportColumn::decimal('satisfaccion_formulario', 6, 2, 'Satisfacción del formulario'),
            ReportColumn::date('fecha', 'Fecha', indexed: true),
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
        $client = new Client($app);

        // Evaluations are agency-scoped through the form. Without this every company's rebuild
        // would load all ~200k answers, so the same answer would exist under all four agencies
        // and any total across companies would be four times reality.
        $agencyId = $company->get(CustomFieldEnum::INTRAS_AGENCY_ID->value);

        if ($agencyId === null) {
            return;
        }

        $peopleIdMap = $this->legacyIdMap(People::class, CustomFieldEnum::INTRAS_PARTICIPANT_ID->value);
        $versionIdMap = $this->legacyIdMap(EventVersion::class, CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value);

        // With nothing imported for this company there is nothing to attribute answers to, and
        // every row would be an orphan.
        if ($peopleIdMap === [] && $versionIdMap === []) {
            return;
        }

        $query = $client->table('evaluations_answers as a')
            ->join('evaluations_filled_forms as ff', 'ff.filled_forms_id', '=', 'a.form_filled_id')
            ->join('evaluations_forms as f', 'f.form_id', '=', 'ff.form_id')
            ->leftJoin('evaluations_questions as q', 'q.question_id', '=', 'a.questions_id')
            ->leftJoin('evaluations_questions_types as qt', 'qt.question_type_id', '=', 'q.evaluations_questions_type_id')
            ->leftJoin('evaluations_modules as mfor', 'mfor.id', '=', 'f.for_evaluations_modules_id')
            ->leftJoin('evaluations_modules as mby', 'mby.id', '=', 'f.by_evaluations_modules_id')
            ->where('a.is_deleted', 0)
            ->where('ff.is_deleted', 0)
            ->where('f.agencies_id', (int) $agencyId)
            ->select(
                'a.answer_id',
                'a.content',
                'a.exclude_in_reports',
                'a.created_at',
                'ff.filled_forms_id',
                'ff.filler_id',
                'ff.filled_to_id',
                'ff.satisfaction',
                'f.form_name',
                'mfor.name as evalua_a',
                'mby.name as evaluado_por',
                'q.question_id',
                'q.title as pregunta',
                'q.ordering',
                'qt.name as tipo_pregunta',
            );

        if ($ids !== null) {
            $query->whereIn('a.answer_id', $ids);
        }

        foreach ($this->cursorInChunks($query) as $rows) {
            $peopleIds = [];
            $versionIds = [];

            foreach ($rows as $row) {
                $peopleId = $peopleIdMap[(int) $row->filler_id] ?? null;
                $versionId = $versionIdMap[(int) $row->filled_to_id] ?? null;

                if ($peopleId !== null) {
                    $peopleIds[$peopleId] = true;
                }

                if ($versionId !== null) {
                    $versionIds[$versionId] = true;
                }
            }

            $people = $this->flatRows('ejecutivo', 'peoples_id', array_keys($peopleIds));
            $versions = $this->flatRows('evento_version', 'version_id', array_keys($versionIds));

            foreach ($rows as $row) {
                $peopleId = $peopleIdMap[(int) $row->filler_id] ?? null;
                $versionId = $versionIdMap[(int) $row->filled_to_id] ?? null;

                $person = $peopleId === null ? null : ($people[$peopleId] ?? null);
                $version = $versionId === null ? null : ($versions[$versionId] ?? null);

                $numeric = is_numeric($row->content);

                yield [
                    'respuesta_id' => (int) $row->answer_id,
                    'companies_id' => $company->getId(),

                    'formulario_id' => (int) $row->filled_forms_id,
                    'formulario' => $row->form_name,
                    'evalua_a' => $row->evalua_a,
                    'evaluado_por' => $row->evaluado_por,

                    'peoples_id' => $peopleId,
                    'pa_code' => $person?->pa_code,
                    'ejecutivo' => $person?->nombre_completo,
                    'empresa' => $person?->empresa,

                    'version_id' => $versionId,
                    'evento' => $version?->evento,
                    'version' => $version?->version,
                    'tipo' => $version?->tipo,
                    'clase' => $version?->clase,
                    'fecha_evento' => $version?->fecha_inicio,

                    'pregunta_id' => $row->question_id === null ? null : (int) $row->question_id,
                    'pregunta' => $row->pregunta,
                    'tipo_pregunta' => $row->tipo_pregunta,
                    'orden' => $row->ordering === null ? null : (int) $row->ordering,

                    'respuesta' => $row->content,
                    'puntuacion' => $numeric ? (float) $row->content : null,
                    'es_numerica' => $numeric ? 1 : 0,
                    'excluir_de_reportes' => empty($row->exclude_in_reports) ? 0 : 1,

                    'satisfaccion_formulario' => $row->satisfaction,
                    'fecha' => $row->created_at === null ? null : substr((string) $row->created_at, 0, 10),
                ];
            }
        }
    }

    /**
     * Nothing in Kanvas invalidates an evaluation answer — the source is the legacy database, so
     * this table is only ever brought up to date by a full rebuild.
     *
     * @return array<class-string, callable(object): array<int, int>>
     */
    #[Override]
    public function invalidatedBy(): array
    {
        return [];
    }

    /**
     * Page the legacy query by primary key.
     *
     * `cursor()` on the legacy connection would hold one result set open for the length of the
     * whole rebuild; keyset paging keeps each round trip short and restartable.
     *
     * @return iterable<array<int, object>>
     */
    protected function cursorInChunks(object $query): iterable
    {
        $lastId = 0;

        while (true) {
            $page = (clone $query)
                ->where('a.answer_id', '>', $lastId)
                ->orderBy('a.answer_id')
                ->limit(self::CHUNK)
                ->get()
                ->all();

            if ($page === []) {
                return;
            }

            yield $page;

            $lastId = (int) end($page)->answer_id;
        }
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
     * @return array<int, int> legacy id => kanvas id
     */
    protected function legacyIdMap(string $modelClass, string $customFieldName): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('model_name', $modelClass)
            ->where('name', $customFieldName)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->mapWithKeys(fn ($kanvasId, $legacyId) => [(int) $legacyId => (int) $kanvasId])
            ->all();
    }
}
