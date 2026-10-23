<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Number;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;

/**
 * Of the leads marked Sold in a window, how many did the AI agent talk to and how many went
 * through an Engage action before the sale. The dealer's question is "did Sally / did an Action
 * help close this?", so a touch counts only inside the lead's own life: after it was created and
 * no later than `closed_at`. A follow-up sent after the sale is not assistance.
 *
 * Sold is a status name (LeadStatus::isSoldName) and the window is `leads.closed_at`, stamped by
 * the observer since 2026-10-23 with no backfill, so an earlier sale is invisible here by design.
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

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly AnalyticsRequest $request,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $soldStatusIds = LeadStatus::soldIdsFor($this->app, $this->company);

        if ($soldStatusIds === []) {
            return $this->present(collect(), [], [], 'This company has no lead status whose name reads as sold.');
        }

        $sold = Lead::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->whereIn('leads_status_id', $soldStatusIds)
            ->whereBetween('closed_at', [$this->request->from->copy()->utc(), $this->request->to->copy()->utc()])
            ->orderBy('closed_at')
            ->get(['id', 'title', 'people_id', 'created_at', 'closed_at', 'leads_status_id']);

        if ($sold->isEmpty()) {
            return $this->present($sold, [], [], null);
        }

        return $this->present(
            $sold,
            $this->aiTouchesByLead($sold),
            $this->engagementTouchesByLead($sold),
            null,
        );
    }

    /**
     * @param Collection<int, Lead> $sold
     * @param array<int, array<int, Carbon>> $aiTouches lead id => AI message timestamps
     * @param array<int, array{shared_action: array<int, Carbon>, submitted_action: array<int, Carbon>}> $engagementTouches
     * @return array<string, mixed>
     */
    private function present(
        Collection $sold,
        array $aiTouches,
        array $engagementTouches,
        ?string $note
    ): array {
        $counts = ['ai' => 0, 'action' => 0, 'shared_action' => 0, 'submitted_action' => 0, 'either' => 0, 'both' => 0];
        $leads = [];

        foreach ($sold as $lead) {
            $leadId = (int) $lead->getId();
            $touches = $engagementTouches[$leadId] ?? ['shared_action' => [], 'submitted_action' => []];

            $ai = $this->touchedDuringLife($lead, $aiTouches[$leadId] ?? []);
            $sharedAction = $this->touchedDuringLife($lead, $touches['shared_action']);
            $submitted = $this->touchedDuringLife($lead, $touches['submitted_action']);
            $action = $sharedAction || $submitted;

            $counts['ai'] += (int) $ai;
            $counts['action'] += (int) $action;
            $counts['shared_action'] += (int) $sharedAction;
            $counts['submitted_action'] += (int) $submitted;
            $counts['either'] += (int) ($ai || $action);
            $counts['both'] += (int) ($ai && $action);

            if (count($leads) < self::MAX_LISTED) {
                $leads[] = [
                    'lead_id' => $leadId,
                    'title' => $lead->title,
                    'closed_at' => $lead->closed_at?->toDateString(),
                    'assisted_by_ai' => $ai,
                    'shared_action' => $sharedAction,
                    'submitted_action' => $submitted,
                ];
            }
        }

        $total = $sold->count();

        return [
            'sold' => $total,
            'assisted_by_ai' => ['count' => $counts['ai'], 'share' => Number::ratio($counts['ai'], $total)],
            'assisted_by_action' => [
                'count' => $counts['action'],
                'share' => Number::ratio($counts['action'], $total),
                'shared_action' => $counts['shared_action'],
                'submitted_action' => $counts['submitted_action'],
            ],
            'assisted_by_either' => $counts['either'],
            'assisted_by_both' => $counts['both'],
            'unassisted' => $total - $counts['either'],
            'leads' => $leads,
            'leads_truncated' => $total > count($leads),
            'note' => $note,
        ];
    }

    /**
     * Every lead here came through the `closed_at` range, so both bounds exist.
     *
     * @param array<int, Carbon> $touches
     */
    private function touchedDuringLife(Lead $lead, array $touches): bool
    {
        foreach ($touches as $touch) {
            if ($touch->between($lead->created_at, $lead->closed_at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Collection<int, Lead> $sold
     * @return array<int, array<int, Carbon>>
     */
    private function aiTouchesByLead(Collection $sold): array
    {
        $typeIds = MessagesTypesRepository::getCommunicationTypeIds($this->app);

        if ($typeIds === []) {
            return [];
        }

        $leadsByPeople = [];
        foreach ($sold as $lead) {
            if ((int) $lead->people_id > 0) {
                $leadsByPeople[(int) $lead->people_id][] = (int) $lead->getId();
            }
        }

        $aiMessages = Message::query()
            ->where('messages.apps_id', $this->app->getId())
            ->where('messages.companies_id', $this->company->getId())
            ->where('messages.is_deleted', 0)
            ->where('messages.sender_type', MessageSenderTypeEnum::AGENT->value)
            ->whereIn('messages.message_types_id', $typeIds)
            ->whereBetween('messages.created_at', [$sold->min('created_at'), $sold->max('closed_at')]);

        $touches = [];

        $linked = (clone $aiMessages)
            ->join('app_module_message', 'app_module_message.message_id', '=', 'messages.id')
            ->where('app_module_message.system_modules', Lead::class)
            ->whereIn('app_module_message.entity_id', $sold->pluck('id')->all())
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
     * @param Collection<int, Lead> $sold
     * @return array<int, array{shared_action: array<int, Carbon>, submitted_action: array<int, Carbon>}>
     */
    private function engagementTouchesByLead(Collection $sold): array
    {
        $engagements = Engagement::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->whereIn('leads_id', $sold->pluck('id')->all())
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
