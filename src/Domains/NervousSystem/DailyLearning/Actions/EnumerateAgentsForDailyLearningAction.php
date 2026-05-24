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
use Kanvas\NervousSystem\DailyLearning\Services\CycleWindowResolverService;

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
        $window = CycleWindowResolverService::resolve($this->app, $this->company, $this->cycleDate);
        $dayStart = $window['dayStart'];
        $dayEnd = $window['dayEnd'];

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

}
