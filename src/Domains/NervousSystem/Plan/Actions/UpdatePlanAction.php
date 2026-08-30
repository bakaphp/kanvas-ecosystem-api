<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

class UpdatePlanAction
{
    public function __construct(
        protected readonly Plan $plan,
        protected readonly PlanData $data,
        protected readonly bool $fromSync = false,
    ) {
    }

    public function execute(): Plan
    {
        return DB::connection('intelligence')->transaction(function (): Plan {
            $oldStatus = $this->plan->status;

            // Demote sibling missions BEFORE saving when this plan is
            // being promoted to its swarm's active mission. Skip self
            // so an idempotent no-op write doesn't demote itself.
            if ($this->data->isSwarmMission && $this->data->swarm !== null) {
                Plan::query()
                    ->where('swarm_id', $this->data->swarm->getId())
                    ->where('is_swarm_mission', 1)
                    ->where('is_deleted', 0)
                    ->where('id', '!=', $this->plan->id)
                    ->update(['is_swarm_mission' => 0]);
            }

            $this->plan->title = $this->data->title;
            $this->plan->project_id = $this->data->project?->getId();
            $this->plan->description = $this->data->description;
            $this->plan->priority = $this->data->priority;
            $this->plan->deadline_at = $this->data->deadlineAt;
            $this->plan->input = $this->data->input;
            $this->plan->output = $this->data->output;
            $this->plan->confidence_score = $this->data->confidenceScore !== null
                ? (string) $this->data->confidenceScore
                : null;
            $this->plan->requires_human_approval = $this->data->requiresHumanApproval;
            $this->plan->swarm_id = $this->data->swarm?->getId();
            $this->plan->is_swarm_mission = $this->data->isSwarmMission;
            $this->plan->impact_summary = $this->data->impactSummary;
            $this->plan->status_pill = $this->data->statusPill;

            $newStatus = $this->data->status->value;

            // Asking for approval mid-flight has to actually gate, the way it already does at
            // creation. Setting the flag alone changed nothing: the plan stayed active, the loop kept
            // dispatching, and "waiting for approval" was a sentence in a comment that nothing
            // enforced (plan 25667). Already-approved plans are left alone — approval is not re-asked.
            if ($this->plan->requires_human_approval
                && $this->plan->approved_at === null
                && $newStatus === PlanStatusEnum::ACTIVE->value
            ) {
                $newStatus = PlanStatusEnum::AWAITING_APPROVAL->value;
            }

            if ($newStatus === 'active' && $this->plan->started_at === null) {
                $this->plan->started_at = Carbon::now();
            }

            if (in_array($newStatus, ['done', 'failed', 'cancelled'], true)
                && $this->plan->completed_at === null
            ) {
                $this->plan->completed_at = Carbon::now();
            }

            $this->plan->status = $newStatus;
            $this->plan->saveOrFail();

            if ($this->data->files !== []) {
                $this->plan->addMultipleFilesFromUrl($this->data->files);
            }

            $this->plan->emitLedgerEvent('plan.updated', payload: [
                'status_from' => $oldStatus,
                'status_to' => $newStatus,
                'completion_pct' => $this->plan->completion_pct,
            ]);

            $this->plan->broadcastChange(
                changeType: PlanChangeTypeEnum::UPDATED,
                previousStatus: $oldStatus,
                fromSync: $this->fromSync,
            );

            // Org-level milestone — only on the actual transition into a
            // terminal state (not on subsequent saves while already terminal).
            if ($oldStatus !== $newStatus
                && in_array($newStatus, ['done', 'failed', 'cancelled'], true)
            ) {
                new PostPlanCompletionToSwarmAction($this->plan)->execute();
            }

            return $this->plan->fresh() ?? $this->plan;
        });
    }
}
