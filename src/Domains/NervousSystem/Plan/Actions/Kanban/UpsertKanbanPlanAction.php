<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions\Kanban;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\KanbanStatusMapper;

/**
 * Upsert a Kanvas Plan from a runtime root task. Rewrites the Plan only when the runtime's raw
 * status or title changed vs the stored status. Mirrors the kanban context into `input` and the
 * worker handoff into `output`, and stamps the runtime's started/completed times. Every write is
 * `fromSync` so it neither wakes the agent nor echoes back as a push.
 */
final class UpsertKanbanPlanAction
{
    public function __construct(
        private readonly KanbanTask $root,
        private readonly ?Plan $existing,
        private readonly AgentDeployment $deployment,
        private readonly Agent $agent,
        private readonly ?string $board = null,
    ) {
    }

    public function execute(): Plan
    {
        $app = $this->deployment->app;
        $company = $this->deployment->company;
        $mapped = KanbanStatusMapper::toPlanStatus($this->root->status);
        $rawStatus = $this->root->status->value;
        $title = $this->root->title !== '' ? $this->root->title : '(untitled)';

        $becameDone = false;

        if ($this->existing === null) {
            $plan = new CreatePlanAction(
                new PlanData(
                    app: $app,
                    company: $company,
                    title: $title,
                    planType: 'hermes_kanban',
                    agent: $this->agent,
                    // Owner = the agent's user so PlanObserver creates the Activities channel
                    // (it no-ops without an owner) — the comment bridge + done-summary post need it.
                    user: $this->agent->user,
                    description: $this->root->body,
                    status: $mapped,
                    input: $this->buildInput(),
                    output: $this->buildOutput(),
                ),
                fromSync: true,
            )->execute();
            $becameDone = $mapped === PlanStatusEnum::DONE;
        } elseif (
            $this->existing->get(KanbanCustomFieldEnum::STATUS->value) !== $rawStatus
            || $this->existing->title !== $title
        ) {
            $plan = new UpdatePlanAction(
                $this->existing,
                PlanData::forUpdate(
                    $this->existing,
                    $app,
                    $company,
                    $this->updateData(
                        $title,
                        $mapped->value
                    )
                ),
                fromSync: true,
            )->execute();
            $becameDone = $mapped === PlanStatusEnum::DONE;
        } else {
            $plan = $this->existing;
        }

        $this->applyTimestamps($plan);
        $this->writeLink($plan, $rawStatus);

        // Post the worker's closing summary onto the plan's Activities channel on the done transition,
        // so it's visible there (a board-driven agent has no chat-wake to do it). Fires once — a no-op
        // re-sync doesn't re-enter the changed branch.
        if ($becameDone && $this->root->latestSummary !== null && $this->root->latestSummary !== '') {
            new PostPlanActivityMessageAction($plan, $this->root->latestSummary)->execute();
        }

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function updateData(string $title, string $status): array
    {
        $data = [
            'title' => $title,
            'description' => $this->root->body,
            'status' => $status,
        ];

        $output = $this->buildOutput();
        if ($output !== null) {
            $data['output'] = $output;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInput(): array
    {
        return [
            'source' => 'hermes_kanban',
            'external_task_id' => $this->root->id,
            'assignee' => $this->root->assignee,
            'priority' => $this->root->priority,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildOutput(): ?array
    {
        if ($this->root->latestSummary === null && $this->root->metadata === null) {
            return null;
        }

        return [
            'summary' => $this->root->latestSummary,
            'metadata' => $this->root->metadata,
        ];
    }

    // CreatePlanAction never sets started/completed and a direct →done skips the active transition,
    // so stamp the runtime's own times (epoch) here; fall back to completed for a missing start.
    private function applyTimestamps(Plan $plan): void
    {
        $changed = false;

        if ($this->root->startedAt !== null) {
            $plan->started_at = Carbon::createFromTimestamp($this->root->startedAt);
            $changed = true;
        }

        if ($this->root->completedAt !== null) {
            $plan->completed_at = Carbon::createFromTimestamp($this->root->completedAt);

            if ($plan->started_at === null) {
                $plan->started_at = $plan->completed_at;
            }

            $changed = true;
        }

        if ($changed) {
            $plan->saveQuietly();
        }
    }

    private function writeLink(Plan $plan, string $rawStatus): void
    {
        $plan->set(KanbanCustomFieldEnum::TASK_ID->value, $this->root->id);
        $plan->set(KanbanCustomFieldEnum::STATUS->value, $rawStatus);
        $plan->set(KanbanCustomFieldEnum::DEPLOYMENT_ID->value, (string) $this->deployment->getId());

        if ($this->board !== null && $this->board !== '') {
            $plan->set(KanbanCustomFieldEnum::BOARD->value, $this->board);
        }

        $plan->set(KanbanCustomFieldEnum::SYNCED_AT->value, Carbon::now()->toIso8601String());
    }
}
