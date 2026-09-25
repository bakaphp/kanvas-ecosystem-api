<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The agent's own jobs — what it started, what is still running, and what it cost.
 *
 * Answered from the session rows, so nothing reaches a container and asking is free. Scoped to this
 * agent: one agent must not be able to enumerate another's work, even within the same tenant.
 */
#[AgentTool(name: 'List Self-Hosted Coding Jobs', category: 'coding')]
class ListHarnessCodingJobsTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    private const int MAX_LIMIT = 20;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'list_self_hosted_coding_jobs',
            description: 'List the coding jobs you have started — their status, repository, how long they '
                . 'have been quiet, and what they cost. Use this when asked what you are working on, or to '
                . 'find a job id you no longer have. Costs nothing and touches no running job.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'only_running',
                type: PropertyType::BOOLEAN,
                description: 'True to list only jobs that have not finished.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many to return, newest first. Defaults to 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?bool $only_running = null, ?int $limit = null): array
    {
        $query = AgentTaskSession::query()
            ->notDeleted()
            ->fromApp($this->agent->app)
            ->fromCompany($this->agent->company)
            ->where('agent_id', $this->agent->getId())
            ->orderByDesc('id');

        if ($only_running === true) {
            $query->live();
        }

        $sessions = $query->limit(max(1, min($limit ?? 10, self::MAX_LIMIT)))->get();

        if ($sessions->isEmpty()) {
            return $this->noop(
                ['jobs' => []],
                guidance: $only_running === true
                    ? 'You have no coding jobs running right now.'
                    : 'You have not started any coding jobs yet.'
            );
        }

        $jobs = [];

        foreach ($sessions as $session) {
            $jobs[] = [
                'job_id' => $session->task_id,
                'status' => $session->status,
                'repository' => $session->repo_slug,
                'waiting_on_a_human' => $session->harnessStatus()->isWaitingOnAHuman(),
                'seconds_since_activity' => $session->secondsSinceHeartbeat(),
                'estimated_cost_usd' => $session->estimated_cost,
                'branch' => $session->branch,
                'pull_request' => $session->pull_request_url,
                'summary' => $session->task?->title,
            ];
        }

        return $this->ok(['jobs' => $jobs, 'count' => count($jobs)]);
    }
}
