<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Project portfolio health — the aggregate companion to list_projects. Instead of listing individual
 * projects it answers "how are things going overall": how many projects are open, the breakdown by
 * status, average completion, and how many are overdue, blocked, at risk, or due soon. Read-only,
 * company-scoped. Answers "how's delivery looking?", "how many projects are behind?", "portfolio status".
 */
#[AgentTool(name: 'Get Project Analytics', category: 'nervous_system')]
class GetProjectAnalyticsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_project_analytics',
            description: 'Project portfolio health at a glance: open project count, breakdown by status, average '
                . 'completion %, and overdue / blocked / at-risk / due-soon counts. Use for "how is delivery going?", '
                . '"how many projects are behind?", "portfolio status". The aggregate companion to list_projects — '
                . 'reporting only.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'due_soon_days', type: PropertyType::INTEGER, description: 'Window (in days from now) that counts a project as "due soon". Default 7, max 90.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $due_soon_days = null): array
    {
        $dueSoonDays = max(1, min($due_soon_days ?? 7, 90));
        $now = Carbon::now();
        $dueSoonCutoff = $now->copy()->addDays($dueSoonDays);

        $base = fn () => Project::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted();

        $byStatus = $base()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Open projects are naturally low-cardinality per tenant, so load them once and derive every
        // metric from the same rows through the model's own isOverdue()/isAtRisk() predicates.
        $open = $base()->whereIn('status', ProjectStatusEnum::openStatusValues())->get();

        $dueSoon = $open->filter(fn (Project $p): bool => $p->deadline_at !== null
            && $p->completion_pct < 100
            && $p->deadline_at->between($now, $dueSoonCutoff))->count();

        return [
            'status' => 'success',
            'open_projects' => $open->count(),
            'avg_completion_pct' => $open->isEmpty() ? 0 : (int) round((float) $open->avg('completion_pct')),
            'overdue' => $open->filter(fn (Project $p): bool => $p->isOverdue())->count(),
            'blocked' => $open->where('status', ProjectStatusEnum::BLOCKED->value)->count(),
            'due_soon' => $dueSoon,
            'due_soon_window_days' => $dueSoonDays,
            'at_risk' => $open->filter(fn (Project $p): bool => $p->isAtRisk())->count(),
            'by_status' => $byStatus->all(),
        ];
    }
}
