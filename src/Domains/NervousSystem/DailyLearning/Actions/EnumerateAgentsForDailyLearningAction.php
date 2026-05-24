<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;

/**
 * Discovery for the scheduled daily-learning sweep — returns the agents
 * that actually produced conversation activity on $cycleDate (in company
 * timezone) and therefore have something to summarize.
 *
 * Empty days produce no work — the per-agent action would just early-return
 * on empty conversations anyway, but emitting empty jobs wastes queue
 * capacity and obscures the agent-runtime queue depth metric.
 */
class EnumerateAgentsForDailyLearningAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly Carbon $cycleDate,
    ) {
    }

    /**
     * @return Collection<int, Agent>
     */
    public function execute(): Collection
    {
        $timezone = $this->resolveTimezone();
        // Re-parse from the YMD label so the date is anchored *in* the target
        // tz rather than shifted into it — `setTimezone()` rotates the moment,
        // which slides the day window backward for west-of-UTC zones.
        $cycleLabel = $this->cycleDate->toDateString();
        $dayStart = Carbon::parse($cycleLabel, $timezone)->startOfDay()->utc();
        $dayEnd = Carbon::parse($cycleLabel, $timezone)->endOfDay()->utc();

        // Conversation ids in the day window: filter on the messages table
        // (it carries the timestamp); the agent_conversations.updated_at is
        // ingestion-time and unreliable for "did anything happen on date X".
        $conversationIds = AgentConversationMessage::query()
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->distinct()
            ->pluck('conversation_id');

        if ($conversationIds->isEmpty()) {
            /** @var Collection<int, Agent> */
            return Agent::query()->whereRaw('1 = 0')->get();
        }

        $agentIds = AgentConversation::query()
            ->whereIn('id', $conversationIds)
            ->where('apps_id', (int) $this->app->getId())
            ->where('companies_id', (int) $this->company->getId())
            ->whereNotNull('agent_id')
            ->distinct()
            ->pluck('agent_id');

        if ($agentIds->isEmpty()) {
            /** @var Collection<int, Agent> */
            return Agent::query()->whereRaw('1 = 0')->get();
        }

        /** @var Collection<int, Agent> $agents */
        $agents = Agent::query()
            ->whereIn('id', $agentIds)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->orderBy('id')
            ->get();

        return $agents;
    }

    /**
     * Company timezone → app timezone → UTC, in that order. Companies.timezone
     * is a real DB column (nullable in practice despite the optimistic
     * `@property string` docblock); Apps has no timezone column so its
     * value comes from custom_fields via ->get().
     */
    private function resolveTimezone(): string
    {
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
