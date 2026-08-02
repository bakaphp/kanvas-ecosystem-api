<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Coding;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Models\Task;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List My Coding Jobs')]
class ListMyCodingJobsTool extends Tool
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'list_my_coding_jobs',
            description: 'List the coding jobs you have dispatched, newest first, with their status and '
                . 'pull-request URL. Use this when you need a job_id you no longer have, or to answer '
                . '"what are you working on / how are the jobs going" without a specific id.',
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
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of jobs to return. Default 20, capped at 50.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null): array
    {
        $cap = max(1, min($limit ?? 20, 50));

        $jobs = Task::query()
            ->where('agent_id', $this->agent->getId())
            ->whereHas('plan', fn (Builder $q) => $q->where('plan_type', 'coding_job'))
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->latest('id')
            ->limit($cap)
            ->get()
            ->map(fn (Task $task): array => [
                'job_id' => $task->getId(),
                'task_status' => $task->status,
                'pidev_status' => $task->get(TaskCustomFieldEnum::PIDEV_STATUS->value),
                'repo' => $task->get(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value),
                'pull_request_url' => $task->get(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value),
                'task' => $task->title,
            ])
            ->all();

        return [
            'status' => 'success',
            'count' => count($jobs),
            'jobs' => $jobs,
        ];
    }
}
