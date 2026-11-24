<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Number;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Enums\AttributionPopulationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;

/**
 * Which leads the AI agent talked to and which went through an Engage action, and whether that
 * made a difference. The dealer's question is "did Sally / did an Action help close this?", so a
 * touch counts only inside the lead's own life: after it was created and no later than
 * `closed_at` (or now, while it is still open). A follow-up sent after the sale is not assistance.
 *
 * The population decides the window column (AttributionPopulationEnum::dateColumn()): sold leads
 * are taken by `closed_at`, stamped by the observer since 2026-10-23 with no backfill, so an
 * earlier sale is invisible here by design; open and all leads are taken by `created_at`, and for
 * those the `outcomes` block compares the sold rate of assisted against unassisted leads, which is
 * the impact figure.
 *
 * Leads live on `crm`, messages on `social` and engagements on `action_engine`, so this is three
 * reads folded in PHP, never a join. An AI touch is found two ways, because both links exist and
 * neither is complete on its own: the `app_module_message` row that names the lead, and the
 * message's `people_id` (the person the lead belongs to), which the backfill wrote onto rows that
 * were never linked to the lead itself. Every AI agent of the company counts as "the AI".
 */
class BuildAssistedSalesAttributionAction
{
    private const int MAX_LISTED = 100;

    /** @var array<int, int> */
    private array $soldStatusIds = [];

    /**
     * @param array<int, int> $leadTypeIds restrict to these lead types; empty means every type. A
     *                                      DMS sync files service appointments as leads too, so a
     *                                      dealer's "leads" usually means Internet + Phone + walk-in.
     */
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly AnalyticsRequest $request,
        protected readonly AttributionPopulationEnum $population = AttributionPopulationEnum::SOLD,
        protected readonly array $leadTypeIds = [],
        protected readonly bool $withTypeBreakdown = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->soldStatusIds = LeadStatus::soldIdsFor($this->app, $this->company);

        if ($this->soldStatusIds === [] && $this->population === AttributionPopulationEnum::SOLD) {
            return $this->present(collect(), [], [], 'This company has no lead status whose name reads as sold.');
        }

        $leads = $this->populationQuery()
            ->orderBy($this->population->dateColumn())
            ->with(['status:id,name', 'type:id,name'])
            ->get(['id', 'title', 'people_id', 'created_at', 'closed_at', 'leads_status_id', 'leads_types_id']);

        if ($leads->isEmpty()) {
            return $this->present($leads, [], [], null);
        }

        return $this->present(
            $leads,
            $this->aiTouchesByLead($leads),
            $this->engagementTouchesByLead($leads),
            null,
        );
    }

    private function populationQuery(): Builder
    {
        $query = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereBetween(
                $this->population->dateColumn(),
                [$this->request->from->copy()->utc(), $this->request->to->copy()->utc()],
            )
            ->when($this->leadTypeIds !== [], fn (Builder $q) => $q->whereIn('leads_types_id', $this->leadTypeIds));

        return match ($this->population) {
            AttributionPopulationEnum::SOLD => $query->whereIn('leads_status_id', $this->soldStatusIds),
            AttributionPopulationEnum::OPEN => $query->hasOpenLeadStatus($this->company),
            AttributionPopulationEnum::ALL => $query,
        };
    }

    /**
     * @param Collection<int, Lead> $leads
     * @param array<int, array<int, Carbon>> $aiTouches lead id => AI message timestamps
     * @param array<int, array{shared_action: array<int, Carbon>, submitted_action: array<int, Carbon>}> $engagementTouches
     * @return array<string, mixed>
     */
    private function present(
        Collection $leads,
        array $aiTouches,
        array $engagementTouches,
        ?string $note
    ): array {
        $rows = [];

        foreach ($leads as $lead) {
            $leadId = (int) $lead->getId();
            $touches = $engagementTouches[$leadId] ?? ['shared_action' => [], 'submitted_action' => []];
            $sharedAction = $this->touchedDuringLife($lead, $touches['shared_action']);
            $submitted = $this->touchedDuringLife($lead, $touches['submitted_action']);

            $rows[] = [
                'lead_id' => $leadId,
                'title' => $lead->title,
                'created_at' => $lead->created_at?->toDateString(),
                'closed_at' => $lead->closed_at?->toDateString(),
                'status' => $lead->statusName(),
                'type' => $lead->type?->name,
                'sold' => in_array((int) $lead->leads_status_id, $this->soldStatusIds, true),
                'assisted_by_ai' => $this->touchedDuringLife($lead, $aiTouches[$leadId] ?? []),
                'assisted_by_action' => $sharedAction || $submitted,
                'shared_action' => $sharedAction,
                'submitted_action' => $submitted,
            ];
        }

        $total = count($rows);
        $counts = ['sold' => 0, 'ai' => 0, 'action' => 0, 'shared' => 0, 'submitted' => 0, 'either' => 0, 'both' => 0];

        foreach ($rows as $row) {
            $counts['sold'] += (int) $row['sold'];
            $counts['ai'] += (int) $row['assisted_by_ai'];
            $counts['action'] += (int) $row['assisted_by_action'];
            $counts['shared'] += (int) $row['shared_action'];
            $counts['submitted'] += (int) $row['submitted_action'];
            $counts['either'] += (int) ($row['assisted_by_ai'] || $row['assisted_by_action']);
            $counts['both'] += (int) ($row['assisted_by_ai'] && $row['assisted_by_action']);
        }

        $result = [
            'population' => $this->population->value,
            'leads' => $total,
            'sold' => $counts['sold'],
            'assisted_by_ai' => ['count' => $counts['ai'], 'share' => Number::ratio($counts['ai'], $total)],
            'assisted_by_action' => [
                'count' => $counts['action'],
                'share' => Number::ratio($counts['action'], $total),
                'shared_action' => $counts['shared'],
                'submitted_action' => $counts['submitted'],
            ],
            'assisted_by_either' => $counts['either'],
            'assisted_by_both' => $counts['both'],
            'unassisted' => $total - $counts['either'],
        ];

        if ($this->withTypeBreakdown) {
            $result['by_type'] = $this->byType($rows);
        }

        if ($this->population !== AttributionPopulationEnum::SOLD) {
            $result['outcomes'] = [
                'assisted_by_ai' => $this->outcome($rows, static fn (array $row): bool => $row['assisted_by_ai']),
                'not_assisted_by_ai' => $this->outcome($rows, static fn (array $row): bool => ! $row['assisted_by_ai']),
                'assisted_by_action' => $this->outcome($rows, static fn (array $row): bool => $row['assisted_by_action']),
                'not_assisted_by_action' => $this->outcome($rows, static fn (array $row): bool => ! $row['assisted_by_action']),
                'unassisted' => $this->outcome($rows, static fn (array $row): bool => ! $row['assisted_by_ai'] && ! $row['assisted_by_action']),
            ];
        }

        $result['lead_rows'] = array_slice($rows, 0, self::MAX_LISTED);
        $result['lead_rows_truncated'] = $total > self::MAX_LISTED;
        $result['note'] = $note;

        return $result;
    }

    /**
     * The mix the manager is really asking about: a store whose DMS files every service visit as a
     * lead reads "532 leads this week" as nonsense until it sees 228 of them are SERVICE.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{type: string, leads: int, sold: int, assisted_by_ai: int, assisted_by_action: int}>
     */
    private function byType(array $rows): array
    {
        $types = [];

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? 'Untyped');
            $types[$type] ??= ['type' => $type, 'leads' => 0, 'sold' => 0, 'assisted_by_ai' => 0, 'assisted_by_action' => 0];
            $types[$type]['leads']++;
            $types[$type]['sold'] += (int) $row['sold'];
            $types[$type]['assisted_by_ai'] += (int) $row['assisted_by_ai'];
            $types[$type]['assisted_by_action'] += (int) $row['assisted_by_action'];
        }

        usort($types, static fn (array $a, array $b): int => $b['leads'] <=> $a['leads']);

        return $types;
    }

    /**
     * How a slice of the population ended: how many leads, how many of them sold, and the rate.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{leads: int, sold: int, sold_rate: float|null}
     */
    private function outcome(array $rows, callable $inSlice): array
    {
        $slice = array_filter($rows, $inSlice);
        $sold = count(array_filter($slice, static fn (array $row): bool => $row['sold']));

        return ['leads' => count($slice), 'sold' => $sold, 'sold_rate' => Number::ratio($sold, count($slice))];
    }

    /**
     * @param array<int, Carbon> $touches
     */
    private function touchedDuringLife(Lead $lead, array $touches): bool
    {
        $until = $lead->closed_at ?? Carbon::now();

        foreach ($touches as $touch) {
            if ($touch->between($lead->created_at, $until)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Collection<int, Lead> $leads
     * @return array<int, array<int, Carbon>>
     */
    private function aiTouchesByLead(Collection $leads): array
    {
        $typeIds = MessagesTypesRepository::getCommunicationTypeIds($this->app);

        if ($typeIds === []) {
            return [];
        }

        $leadsByPeople = [];
        foreach ($leads as $lead) {
            if ((int) $lead->people_id > 0) {
                $leadsByPeople[(int) $lead->people_id][] = (int) $lead->getId();
            }
        }

        $aiMessages = Message::query()
            ->where('messages.apps_id', $this->app->getId())
            ->where('messages.companies_id', $this->company->getId())
            ->where('messages.is_deleted', 0)
            ->notLocked()
            ->where('messages.sender_type', MessageSenderTypeEnum::AGENT->value)
            ->whereIn('messages.message_types_id', $typeIds)
            ->where('messages.created_at', '>=', $leads->min('created_at'));

        $touches = [];

        $linked = (clone $aiMessages)
            ->join('app_module_message', 'app_module_message.message_id', '=', 'messages.id')
            ->where('app_module_message.system_modules', Lead::class)
            ->whereIn('app_module_message.entity_id', $leads->pluck('id')->all())
            ->get(['app_module_message.entity_id as lead_id', 'messages.created_at']);

        foreach ($linked as $row) {
            $touches[(int) $row->lead_id][] = Carbon::parse((string) $row->created_at);
        }

        if ($leadsByPeople !== []) {
            $byPerson = (clone $aiMessages)
                ->whereIn('messages.people_id', array_keys($leadsByPeople))
                ->get(['messages.people_id', 'messages.created_at']);

            foreach ($byPerson as $row) {
                foreach ($leadsByPeople[(int) $row->people_id] ?? [] as $leadId) {
                    $touches[$leadId][] = Carbon::parse((string) $row->created_at);
                }
            }
        }

        return $touches;
    }

    /**
     * A shared action is any catalog action (ActionEnum) at any stage: sent, opened and submitted
     * all mean it reached the customer. A submitted action is one the customer completed. An
     * engagement on a slug outside the catalog is ignored rather than guessed at.
     *
     * @param Collection<int, Lead> $leads
     * @return array<int, array{shared_action: array<int, Carbon>, submitted_action: array<int, Carbon>}>
     */
    private function engagementTouchesByLead(Collection $leads): array
    {
        $engagements = Engagement::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('leads_id', $leads->pluck('id')->all())
            ->with(['stage:id,slug', 'companyAction:id,actions_id', 'companyAction.action:id,slug'])
            ->get(['id', 'leads_id', 'pipelines_stages_id', 'companies_actions_id', 'created_at']);

        $touches = [];

        foreach ($engagements as $engagement) {
            if (ActionEnum::tryFrom((string) $engagement->companyAction?->action?->slug) === null) {
                continue;
            }

            $leadId = (int) $engagement->leads_id;
            $touches[$leadId] ??= ['shared_action' => [], 'submitted_action' => []];
            $at = Carbon::parse((string) $engagement->created_at);

            $touches[$leadId]['shared_action'][] = $at;

            if ($engagement->stage?->slug === ActionStatusEnum::SUBMITTED->value) {
                $touches[$leadId]['submitted_action'][] = $at;
            }
        }

        return $touches;
    }
}
