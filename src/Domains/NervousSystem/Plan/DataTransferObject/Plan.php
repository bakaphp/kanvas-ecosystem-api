<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan as PlanModel;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Plan extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $title,
        public readonly string $planType,
        public readonly ?Agent $agent = null,
        public readonly ?Users $user = null,
        public readonly ?PlanModel $parentPlan = null,
        public readonly ?string $entityNamespace = null,
        public readonly ?int $entityId = null,
        public readonly ?string $description = null,
        public readonly PlanStatusEnum $status = PlanStatusEnum::DRAFT,
        public readonly int $priority = 0,
        public readonly ?Carbon $deadlineAt = null,
        public readonly ?array $input = null,
        public readonly ?array $output = null,
        public readonly ?float $confidenceScore = null,
        public readonly bool $requiresHumanApproval = false,
        public readonly ?AgentSwarm $swarm = null,
        public readonly bool $isSwarmMission = false,
        public readonly ?string $impactSummary = null,
        public readonly ?string $statusPill = null,
        /** @var array<int, array<string, mixed>> */
        public readonly array $files = [],
        public readonly ?Project $project = null,
        public readonly ?Agent $createdByAgent = null,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        Users $requestingUser,
        CompanyInterface $company,
        array $data,
    ): self {
        /** @var Agent|null $agent */
        $agent = isset($data['agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $data['agent_id'], $company, $app)
            : null;

        /** @var PlanModel|null $parentPlan */
        $parentPlan = isset($data['parent_plan_id'])
            ? PlanModel::getByIdFromCompanyApp((int) $data['parent_plan_id'], $company, $app)
            : null;

        /** @var AgentSwarm|null $swarm */
        $swarm = isset($data['swarm_id'])
            ? AgentSwarm::getByIdFromCompanyApp((int) $data['swarm_id'], $company, $app)
            : null;

        /** @var Project|null $project */
        $project = isset($data['project_id'])
            ? Project::getByIdFromCompanyApp((int) $data['project_id'], $company, $app)
            : null;

        return new self(
            app: $app,
            company: $company,
            title: (string) $data['title'],
            planType: (string) $data['plan_type'],
            agent: $agent,
            user: isset($data['users_id']) ? Users::getById((int) $data['users_id']) : $requestingUser,
            parentPlan: $parentPlan,
            entityNamespace: $data['entity_namespace'] ?? null,
            entityId: isset($data['entity_id']) ? (int) $data['entity_id'] : null,
            description: $data['description'] ?? null,
            status: isset($data['status'])
                ? PlanStatusEnum::fromAlias((string) $data['status'])
                : PlanStatusEnum::DRAFT,
            priority: isset($data['priority']) ? (int) $data['priority'] : 0,
            deadlineAt: isset($data['deadline_at']) ? Carbon::parse((string) $data['deadline_at']) : null,
            input: $data['input'] ?? null,
            output: $data['output'] ?? null,
            confidenceScore: isset($data['confidence_score']) ? (float) $data['confidence_score'] : null,
            requiresHumanApproval: (bool) ($data['requires_human_approval'] ?? false),
            swarm: $swarm,
            isSwarmMission: (bool) ($data['is_swarm_mission'] ?? false),
            impactSummary: $data['impact_summary'] ?? null,
            statusPill: $data['status_pill'] ?? null,
            files: (array) ($data['files'] ?? []),
            project: $project,
        );
    }

    public static function forUpdate(
        PlanModel $plan,
        AppInterface $app,
        CompanyInterface $company,
        array $data,
    ): self {
        /** @var AgentSwarm|null $swarm */
        $swarm = array_key_exists('swarm_id', $data)
            ? ($data['swarm_id'] !== null
                ? AgentSwarm::getByIdFromCompanyApp((int) $data['swarm_id'], $company, $app)
                : null)
            : ($plan->swarm_id !== null
                ? AgentSwarm::getByIdFromCompanyApp((int) $plan->swarm_id, $company, $app)
                : null);

        /** @var Project|null $project */
        $project = array_key_exists('project_id', $data)
            ? ($data['project_id'] !== null
                ? Project::getByIdFromCompanyApp((int) $data['project_id'], $company, $app)
                : null)
            : ($plan->project_id !== null
                ? Project::getByIdFromCompanyApp((int) $plan->project_id, $company, $app)
                : null);

        return new self(
            app: $app,
            company: $company,
            title: (string) ($data['title'] ?? $plan->title),
            planType: $plan->plan_type,
            agent: null,
            user: null,
            parentPlan: null,
            entityNamespace: $plan->entity_namespace,
            entityId: $plan->entity_id,
            description: $data['description'] ?? $plan->description,
            status: isset($data['status'])
                ? PlanStatusEnum::fromAlias((string) $data['status'])
                : PlanStatusEnum::from($plan->status),
            priority: isset($data['priority']) ? (int) $data['priority'] : $plan->priority,
            deadlineAt: array_key_exists('deadline_at', $data)
                ? ($data['deadline_at'] !== null ? Carbon::parse((string) $data['deadline_at']) : null)
                : $plan->deadline_at,
            input: array_key_exists('input', $data) ? $data['input'] : $plan->input,
            output: array_key_exists('output', $data) ? $data['output'] : $plan->output,
            confidenceScore: array_key_exists('confidence_score', $data)
                ? ($data['confidence_score'] !== null ? (float) $data['confidence_score'] : null)
                : ($plan->confidence_score !== null ? (float) $plan->confidence_score : null),
            requiresHumanApproval: array_key_exists('requires_human_approval', $data)
                ? (bool) $data['requires_human_approval']
                : (bool) $plan->requires_human_approval,
            swarm: $swarm,
            isSwarmMission: array_key_exists('is_swarm_mission', $data)
                ? (bool) $data['is_swarm_mission']
                : (bool) $plan->is_swarm_mission,
            impactSummary: array_key_exists('impact_summary', $data)
                ? $data['impact_summary']
                : $plan->impact_summary,
            statusPill: array_key_exists('status_pill', $data)
                ? $data['status_pill']
                : $plan->getRawOriginal('status_pill'),
            files: (array) ($data['files'] ?? []),
            project: $project,
        );
    }
}
