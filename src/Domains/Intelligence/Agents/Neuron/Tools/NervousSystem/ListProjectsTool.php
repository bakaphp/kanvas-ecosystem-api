<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Project pipeline visibility: what work is in flight and which projects are in trouble. Returns each
 * project's title, status, completion %, priority, deadline, owner and PM agent, plus an at_risk flag
 * (past deadline and not done, or blocked) so the brain can see where delivery is slipping. Read-only,
 * company-scoped. Answers "what projects are active?", "which projects are at risk?", "what's blocked?".
 */
#[AgentTool(name: 'List Projects', category: 'nervous_system')]
class ListProjectsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_projects',
            description: 'List the company\'s projects with status, completion %, priority, deadline, owner and PM '
                . 'agent, and an at_risk flag. Defaults to open projects (draft/active/on_hold/blocked). Use for '
                . '"what projects are running?", "which projects are at risk or blocked?", "project health". '
                . 'Reporting only.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'status', type: PropertyType::STRING, description: 'Filter to one status: draft, active, on_hold, blocked, done, archived, cancelled. Omit for all OPEN projects.', required: false),
            new ToolProperty(name: 'at_risk_only', type: PropertyType::BOOLEAN, description: 'When true, return only projects flagged at_risk (past deadline and not done, or blocked).', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max projects to return (default 25, max 100).', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $status = null, ?bool $at_risk_only = null, ?int $limit = null): array
    {
        $limit = max(1, min($limit ?? 25, 100));

        $query = Project::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->with(['owner', 'pmAgent']);

        $status = $status !== null ? trim($status) : '';
        if ($status !== '') {
            try {
                $query->where('status', ProjectStatusEnum::fromAlias($status)->value);
            } catch (Throwable) {
                return [
                    'status' => 'error',
                    'message' => 'Unknown project status "' . $status . '". Valid: '
                        . implode(', ', array_map(fn (ProjectStatusEnum $s): string => $s->value, ProjectStatusEnum::cases())) . '.',
                ];
            }
        } else {
            $query->whereIn('status', ProjectStatusEnum::openStatusValues());
        }

        $projects = $query
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->orderByDesc('priority')
            ->limit($limit)
            ->get();

        $rows = $projects
            ->map(fn (Project $project): array => [
                'id' => $project->getId(),
                'title' => $project->title,
                'status' => $project->status,
                'completion_pct' => $project->completion_pct,
                'priority' => $project->priority,
                'deadline_at' => $project->deadline_at?->toDateString(),
                'overdue' => $project->isOverdue(),
                'at_risk' => $project->isAtRisk(),
                'owner' => $project->owner?->displayname,
                'pm_agent' => $project->pmAgent?->name,
            ]);

        if ($at_risk_only === true) {
            $rows = $rows->filter(fn (array $row): bool => $row['at_risk'])->values();
        }

        return [
            'status' => 'success',
            'total' => $rows->count(),
            'at_risk_count' => $rows->filter(fn (array $row): bool => $row['at_risk'])->count(),
            'projects' => $rows->all(),
        ];
    }
}
