<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project as ProjectModel;
use Kanvas\NervousSystem\Project\Models\Workspace;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Project extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Users $owner,
        public readonly string $title,
        public readonly Agent $pmAgent,
        public readonly ?Workspace $workspace = null,
        public readonly ?AgentSwarm $swarm = null,
        public readonly ?ProjectModel $parentProject = null,
        public readonly ?string $objective = null,
        public readonly ?string $description = null,
        public readonly ProjectStatusEnum $status = ProjectStatusEnum::DRAFT,
        public readonly int $priority = 0,
        public readonly ?Carbon $deadlineAt = null,
        public readonly int $heartbeatIntervalMinutes = 15,
        /** @var array<int, array<string, mixed>> */
        public readonly array $files = [],
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        Users $requestingUser,
        CompanyInterface $company,
        array $data,
    ): self {
        if (! isset($data['agent_id'])) {
            throw new ValidationException('A project requires an agent — pass agent_id.');
        }
        /** @var Agent $pmAgent */
        $pmAgent = Agent::getByIdFromCompanyApp((int) $data['agent_id'], $company, $app);

        /** @var Workspace|null $workspace */
        $workspace = isset($data['workspace_id'])
            ? Workspace::getByIdFromCompanyApp((int) $data['workspace_id'], $company, $app)
            : null;

        /** @var AgentSwarm|null $swarm */
        $swarm = isset($data['swarm_id'])
            ? AgentSwarm::getByIdFromCompanyApp((int) $data['swarm_id'], $company, $app)
            : null;

        /** @var ProjectModel|null $parentProject */
        $parentProject = isset($data['parent_project_id'])
            ? ProjectModel::getByIdFromCompanyApp((int) $data['parent_project_id'], $company, $app)
            : null;

        return new self(
            app: $app,
            company: $company,
            owner: $requestingUser,
            title: (string) $data['title'],
            pmAgent: $pmAgent,
            workspace: $workspace,
            swarm: $swarm,
            parentProject: $parentProject,
            objective: $data['objective'] ?? null,
            description: $data['description'] ?? null,
            status: isset($data['status'])
                ? ProjectStatusEnum::fromAlias((string) $data['status'])
                : ProjectStatusEnum::DRAFT,
            priority: isset($data['priority']) ? (int) $data['priority'] : 0,
            deadlineAt: isset($data['deadline_at']) ? Carbon::parse((string) $data['deadline_at']) : null,
            heartbeatIntervalMinutes: isset($data['heartbeat_interval_minutes'])
                ? (int) $data['heartbeat_interval_minutes']
                : 15,
            files: (array) ($data['files'] ?? []),
        );
    }

    public static function forUpdate(
        ProjectModel $project,
        AppInterface $app,
        CompanyInterface $company,
        Users $owner,
        array $data,
    ): self {
        /** @var Workspace|null $workspace */
        $workspace = array_key_exists('workspace_id', $data)
            ? ($data['workspace_id'] !== null
                ? Workspace::getByIdFromCompanyApp((int) $data['workspace_id'], $company, $app)
                : null)
            : ($project->workspace_id !== null
                ? Workspace::getByIdFromCompanyApp($project->workspace_id, $company, $app)
                : null);

        /** @var AgentSwarm|null $swarm */
        $swarm = array_key_exists('swarm_id', $data)
            ? ($data['swarm_id'] !== null
                ? AgentSwarm::getByIdFromCompanyApp((int) $data['swarm_id'], $company, $app)
                : null)
            : ($project->swarm_id !== null
                ? AgentSwarm::getByIdFromCompanyApp($project->swarm_id, $company, $app)
                : null);

        // The PM can be reassigned on update; fall back to the current PM when agent_id isn't sent.
        /** @var Agent $pmAgent */
        $pmAgent = Agent::getByIdFromCompanyApp(
            isset($data['agent_id']) ? (int) $data['agent_id'] : $project->agent_id,
            $company,
            $app,
        );

        return new self(
            app: $app,
            company: $company,
            owner: $owner,
            title: (string) ($data['title'] ?? $project->title),
            pmAgent: $pmAgent,
            workspace: $workspace,
            swarm: $swarm,
            parentProject: null,
            objective: array_key_exists('objective', $data) ? $data['objective'] : $project->objective,
            description: array_key_exists('description', $data) ? $data['description'] : $project->description,
            status: isset($data['status'])
                ? ProjectStatusEnum::fromAlias((string) $data['status'])
                : ProjectStatusEnum::from($project->status),
            priority: isset($data['priority']) ? (int) $data['priority'] : $project->priority,
            deadlineAt: array_key_exists('deadline_at', $data)
                ? ($data['deadline_at'] !== null ? Carbon::parse((string) $data['deadline_at']) : null)
                : $project->deadline_at,
            heartbeatIntervalMinutes: isset($data['heartbeat_interval_minutes'])
                ? (int) $data['heartbeat_interval_minutes']
                : $project->heartbeat_interval_minutes,
            files: (array) ($data['files'] ?? []),
        );
    }
}
