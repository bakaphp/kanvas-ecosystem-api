<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\NervousSystem\DailyLearning\Notifications\DailyLearningDigestNotification;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;

/**
 * Per-company digest fan-out: collects yesterday's AgentDailyCycle rows
 * across all agents for $cycleDate, then dispatches one queued
 * DailyLearningDigestNotification per AgentReport recipient.
 *
 * Designed to be cheap on empty days — if there are no cycles or no
 * recipients, we early-return without emitting the ledger event so the
 * "digest sent" count stays meaningful in dashboards.
 */
class SendDailyLearningDigestAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly Carbon $cycleDate,
    ) {
    }

    public function execute(): int
    {
        $cycleDateLabel = $this->cycleDate->toDateString();

        $cycles = AgentDailyCycle::query()
            ->where('apps_id', (int) $this->app->getId())
            ->where('companies_id', (int) $this->company->getId())
            ->where('cycle_date', $cycleDateLabel)
            ->where('is_deleted', 0)
            ->whereNotNull('morning_briefing')
            ->with('agent')
            ->orderBy('agent_id')
            ->get();

        if ($cycles->isEmpty()) {
            return 0;
        }

        $agents = $this->buildAgentRows($cycles);

        $recipients = new EnumerateAgentReportRecipientsAction($this->app, $this->company)->execute();

        if ($recipients->isEmpty()) {
            Log::info('Daily learning digest: no recipients for company; skipping send', [
                'company_id' => $this->company->getId(),
                'cycle_date' => $cycleDateLabel,
            ]);

            return 0;
        }

        $sent = 0;
        foreach ($recipients as $recipient) {
            NotificationFacade::send(
                [$recipient],
                new DailyLearningDigestNotification(
                    $this->company,
                    $cycleDateLabel,
                    $agents,
                ),
            );
            $sent++;
        }

        $this->emitLedger($sent, count($agents));

        return $sent;
    }

    /**
     * @param  EloquentCollection<int, AgentDailyCycle> $cycles
     * @return list<array<string, mixed>>
     */
    private function buildAgentRows(EloquentCollection $cycles): array
    {
        $timezone = $this->resolveTimezone();
        // Re-parse from the YMD label so the date is anchored *in* the target
        // tz rather than shifted into it — `setTimezone()` rotates the moment,
        // which slides the day window backward for west-of-UTC zones.
        $cycleLabel = $this->cycleDate->toDateString();
        $dayStart = Carbon::parse($cycleLabel, $timezone)->startOfDay()->utc();
        $dayEnd = Carbon::parse($cycleLabel, $timezone)->endOfDay()->utc();

        $rows = [];
        foreach ($cycles as $cycle) {
            $agent = $cycle->agent;
            $agentName = $agent !== null && $agent->name !== ''
                ? $agent->name
                : ('agent #' . $cycle->agent_id);

            $rows[] = [
                'agent_id' => $cycle->agent_id,
                'name' => $agentName,
                'briefing' => $cycle->morning_briefing,
                'proposed_actions' => $cycle->proposed_actions ?? [],
                'durable_facts' => $cycle->durable_facts ?? [],
                'skills_emerged' => $cycle->skills_emerged ?? [],
                'self_improvement_score' => (float) $cycle->self_improvement_score,
                'signed_by_text' => $cycle->signed_by_text,
                'conversation_count' => $this->conversationCountFor($cycle->agent_id, $dayStart, $dayEnd),
                'pushed_to_runtime' => false, // not tracked on AgentDailyCycle; surfaced from ledger in v1.1 if useful
            ];
        }

        return $rows;
    }

    private function conversationCountFor(int $agentId, Carbon $dayStart, Carbon $dayEnd): int
    {
        return AgentConversation::query()
            ->where('agent_id', $agentId)
            ->where('apps_id', (int) $this->app->getId())
            ->where('companies_id', (int) $this->company->getId())
            ->whereHas(
                'messages',
                fn ($q) => $q->whereBetween('created_at', [$dayStart, $dayEnd])
            )
            ->count();
    }

    private function emitLedger(int $recipientCount, int $agentCount): void
    {
        new AppendEventAction(new EventData(
            app: $this->app,
            company: $this->company,
            sourceDomain: 'NervousSystem.DailyLearning',
            eventType: 'agent.daily_learning.digest_sent',
            status: EventStatusEnum::SUCCESS,
            sourceEntityType: Companies::class,
            sourceEntityId: $this->company->getId(),
            actorType: 'System',
            payload: [
                'cycle_date' => $this->cycleDate->toDateString(),
                'recipient_count' => $recipientCount,
                'agent_count' => $agentCount,
            ],
        ))->execute();
    }

    /**
     * Company.timezone is a real column on Companies; Apps has no timezone
     * column so its value comes from custom_fields via ->get().
     */
    private function resolveTimezone(): string
    {
        // Cast + suppress: Companies' `@property string $timezone` docblock
        // is optimistic — the column is nullable in DB so unset rows surface
        // as null at runtime.
        /** @psalm-suppress RedundantCastGivenDocblockType */
        $companyTz = (string) $this->company->timezone;
        if ($companyTz !== '') {
            return $companyTz;
        }

        $appTz = $this->app->get('timezone');
        if (is_string($appTz) && $appTz !== '') {
            return $appTz;
        }

        return 'UTC';
    }
}
