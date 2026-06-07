<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions\Kanban;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\KanbanStatusMapper;

/**
 * Upsert a Kanvas Plan from a runtime root task. Rewrites the Plan only when the runtime's raw
 * status or title actually changed vs the stored status — otherwise a no-op. Every write is
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

        if ($this->existing === null) {
            $plan = new CreatePlanAction(
                new PlanData(
                    app: $app,
                    company: $company,
                    title: $title,
                    planType: 'hermes_kanban',
                    agent: $this->agent,
                    description: $this->root->body,
                    status: $mapped,
                ),
                fromSync: true,
            )->execute();
        } elseif (
            $this->existing->get(KanbanCustomFieldEnum::STATUS->value) !== $rawStatus
            || $this->existing->title !== $title
        ) {
            $plan = new UpdatePlanAction(
                $this->existing,
                PlanData::forUpdate($this->existing, $app, $company, [
                    'title' => $title,
                    'description' => $this->root->body,
                    'status' => $mapped->value,
                ]),
                fromSync: true,
            )->execute();
        } else {
            $plan = $this->existing;
        }

        $this->writeLink($plan, $rawStatus);

        return $plan;
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
