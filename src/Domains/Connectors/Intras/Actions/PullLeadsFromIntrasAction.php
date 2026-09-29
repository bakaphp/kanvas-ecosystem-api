<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\LeadMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Throwable;

class PullLeadsFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?string $lastSyncAt = null,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): int
    {
        $client = new Client($this->app);

        $query = $client->table('quotes')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        // Resolve the stage from the legacy catalog rather than hardcoded ids: `quotes_statuses`
        // rows are install-specific and get added over time, so an id map silently routes new
        // statuses to "drafting".
        $quoteLookupNames = self::loadQuoteLookupNames($client);

        $quoteStatusNames = $client->table('quotes_statuses')
            ->pluck('name', 'id')
            ->map(fn ($name) => trim((string) $name))
            ->all();

        $pipeline = Pipeline::where('apps_id', $this->app->getId())
            ->fromCompany($this->company)
            ->where('is_default', 1)
            ->first();

        $defaultStatus = LeadStatus::where('name', 'Active')->first();
        $branch = $this->company->defaultBranch;
        $count = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (&$count, $pipeline, $defaultStatus, $branch, $quoteStatusNames, $quoteLookupNames) {
            $client = new Client($this->app);
            $relations = self::loadQuoteRelations(
                $client,
                array_map(fn ($r): int => (int) $r->id, $rows->all())
            );

            foreach ($rows as $row) {
                $existing = Lead::fromApp($this->app)
                    ->fromCompany($this->company)
                    ->whereHas(
                        'customFields',
                        fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_QUOTE_ID->value)->where('value', $row->id)
                    )
                    ->first();

                $mapped = LeadMapper::fromIntras($row, $quoteLookupNames);
                $legacyStatus = $quoteStatusNames[(int) $row->quotes_statuses_id] ?? null;
                $stageName = LeadMapper::stageForStatusName($legacyStatus);

                $stage = $pipeline ? PipelineStage::where('pipelines_id', $pipeline->getId())
                    ->where('name', $stageName)
                    ->first() : null;

                // An already-imported quote still has its fields re-applied. Skipping it outright
                // meant a field added to the mapper later never reached the 4,247 quotes already
                // in Kanvas — the import looked idempotent while silently doing nothing.
                $lead = $existing ?? new Lead();

                if ($existing === null) {
                    $people = $this->findPeopleByIntrasParticipantId($row->participants_id);
                    $org = $this->findOrganizationByIntrasCompanyId($row->companies_id);

                    $lead->apps_id = $this->app->getId();
                    $lead->companies_id = $this->company->getId();
                    $lead->companies_branches_id = $branch?->getId() ?? 0;
                    $lead->users_id = $this->user->getId();
                    $lead->leads_owner_id = $this->user->getId();
                    $lead->people_id = $people?->getId() ?? 0;
                    $lead->organization_id = $org?->getId();
                    $lead->title = $mapped['title'];
                    $lead->pipeline_id = $pipeline?->getId() ?? 0;
                    $lead->leads_status_id = $defaultStatus?->getId() ?? 0;
                    $lead->description = $row->info_objectives ?? null;

                    if ($row->created_at !== null) {
                        $lead->created_at = $row->created_at;
                    }
                }

                // The stage tracks the legacy quote status, which moves after the quote exists
                // (EN ELABORACION -> GANADA / PERDIDA), so it is re-applied on every run.
                $lead->pipeline_stage_id = $stage?->getId() ?? $lead->pipeline_stage_id ?? 0;
                $lead->disableWorkflows();

                if ($row->updated_at !== null) {
                    $lead->updated_at = $row->updated_at;
                }

                $lead->saveOrFail();

                $lead->set(CustomFieldEnum::INTRAS_QUOTE_ID->value, $row->id);

                foreach ($mapped['custom_fields'] as $key => $value) {
                    if ($value !== null) {
                        $lead->set($key, $value);
                    }
                }

                // The legacy status verbatim. `stageForStatusName()` maps a vocabulary this
                // install does not use — it expects GANADA / PERDIDA, the data says Aprobada /
                // Rechazada — so every quote lands in the default pipeline stage and the stage
                // cannot be used to tell an approved proposal from a rejected one. Reporting
                // reads this instead; the stage mapping is a separate problem.
                if ($legacyStatus !== null) {
                    $lead->set('estatus', $legacyStatus);
                }

                foreach (['facilitadores', 'eventos_solicitados', 'temas'] as $key) {
                    $lead->set($key, $relations[$key][(int) $row->id] ?? []);
                }

                $count++;
            }
        });

        return $count;
    }

    /**
     * The three things a quote points at that are child rows rather than FK columns:
     * the facilitators it was requested for, the events/themes it covers, and its keywords.
     *
     * None of them were imported, which is why "which companies asked for a proposal with
     * facilitator X" and "who asked about theme Y" had nowhere to look — `quotes_facilitators`
     * alone is 5,960 rows. Stored as name arrays on the Lead rather than pivots, same as the
     * participant's groups and interests: one-to-many sets that are only ever filtered by
     * membership do not need their own grain.
     *
     * @param list<int> $quoteIds
     *
     * @return array{facilitadores: array<int, list<string>>, eventos: array<int, list<string>>, temas: array<int, list<string>>}
     */
    public static function loadQuoteRelations(Client $client, array $quoteIds): array
    {
        if ($quoteIds === []) {
            return ['facilitadores' => [], 'eventos' => [], 'temas' => []];
        }

        return [
            // `facilitators` has no `name` column — it stores first_name / last_name.
            'facilitadores' => self::loadNamedChildren(
                $client,
                'quotes_facilitators',
                'facilitators_id',
                'facilitators',
                $quoteIds,
                "TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))"
            ),
            'eventos_solicitados' => self::loadNamedChildren($client, 'quotes_events', 'events_id', 'events', $quoteIds),
            'temas' => self::loadNamedChildren($client, 'quotes_keywords', 'keywords_id', 'keywords', $quoteIds),
        ];
    }

    /**
     * @param list<int> $quoteIds
     *
     * @return array<int, list<string>>
     */
    protected static function loadNamedChildren(
        Client $client,
        string $pivotTable,
        string $foreignKey,
        string $catalogTable,
        array $quoteIds,
        string $nameExpression = 'c.name'
    ): array {
        $grouped = [];

        try {
            $rows = $client->table($pivotTable . ' as p')
                ->leftJoin($catalogTable . ' as c', 'c.id', '=', 'p.' . $foreignKey)
                ->whereIn('p.quotes_id', $quoteIds)
                ->where('p.is_deleted', 0)
                ->selectRaw('p.quotes_id, ' . $nameExpression . ' as name')
                ->get();
        } catch (Throwable) {
            // A pivot or catalog missing on an agency's install drops that one set, not the run.
            return [];
        }

        foreach ($rows as $row) {
            $name = Str::trimToNull((string) ($row->name ?? ''));

            if ($name === null) {
                continue;
            }

            $grouped[(int) $row->quotes_id][$name] = true;
        }

        return array_map(fn (array $names): array => array_keys($names), $grouped);
    }

    /**
     * Catalogs behind the quote FKs, resolved to names.
     *
     * A catalog missing on an agency's install drops that one field, not the import.
     *
     * @return array<string, array<int, string>>
     */
    public static function loadQuoteLookupNames(Client $client): array
    {
        $names = [];

        foreach (LeadMapper::lookupTables() as $table) {
            try {
                $names[$table] = $client->table($table)
                    ->pluck('name', 'id')
                    ->map(fn ($name) => trim((string) $name))
                    ->all();
            } catch (Throwable) {
                $names[$table] = [];
            }
        }

        return $names;
    }

    protected function findPeopleByIntrasParticipantId(?int $intrasId): ?People
    {
        if ($intrasId === null) {
            return null;
        }

        return People::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_PARTICIPANT_ID->value)->where('value', $intrasId)
            )
            ->first();
    }

    protected function findOrganizationByIntrasCompanyId(?int $intrasId): ?Organization
    {
        if ($intrasId === null) {
            return null;
        }

        return Organization::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_COMPANY_ID->value)->where('value', $intrasId)
            )
            ->first();
    }
}
