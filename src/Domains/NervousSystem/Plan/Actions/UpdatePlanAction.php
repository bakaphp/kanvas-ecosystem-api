<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Updates mutable plan fields. Status transitions to terminal states
 * (done/failed/cancelled) set completed_at; transition to active sets
 * started_at if not already set. Every change emits a `plan.updated` event.
 */
class UpdatePlanAction
{
    public function __construct(
        protected readonly Plan $plan,
        protected readonly PlanData $data,
    ) {
    }

    public function execute(): Plan
    {
        return DB::connection('intelligence')->transaction(function (): Plan {
            $oldStatus = $this->plan->status;

            $this->plan->title = $this->data->title;
            $this->plan->description = $this->data->description;
            $this->plan->priority = $this->data->priority;
            $this->plan->deadline_at = $this->data->deadlineAt;
            $this->plan->input = $this->data->input;
            $this->plan->output = $this->data->output;
            $this->plan->confidence_score = $this->data->confidenceScore !== null
                ? (string) $this->data->confidenceScore
                : null;

            $newStatus = $this->data->status->value;

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

            $this->plan->emitLedgerEvent('plan.updated', payload: [
                'status_from' => $oldStatus,
                'status_to' => $newStatus,
                'completion_pct' => $this->plan->completion_pct,
            ]);

            return $this->plan->fresh() ?? $this->plan;
        });
    }
}
