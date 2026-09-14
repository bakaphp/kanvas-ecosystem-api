<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;

class ResumeScheduledActionAction
{
    public function __construct(
        protected readonly ScheduledAction $action,
    ) {
    }

    public function execute(): ScheduledAction
    {
        $status = ScheduledActionStatusEnum::from($this->action->status);

        if ($status === ScheduledActionStatusEnum::PENDING) {
            return $this->action;
        }

        if ($status !== ScheduledActionStatusEnum::PAUSED) {
            throw new ValidationException(
                "A scheduled action in status '{$status->value}' cannot be resumed.",
            );
        }

        $rearmed = $this->rearmRunAt();

        $this->action->status = ScheduledActionStatusEnum::PENDING->value;
        $this->action->saveOrFail();

        $this->action->emitLedgerEvent('scheduled_action.resumed', payload: [
            'action_type' => $this->action->action_type,
            'recurring' => $this->action->isRecurring(),
            'run_at' => $this->action->run_at->toIso8601String(),
            'rearmed' => $rearmed,
        ]);

        return $this->action;
    }

    /**
     * A recurring row that sat paused past its slot must advance to the next FUTURE occurrence —
     * otherwise resuming replays a stale slot immediately and then fires again on schedule.
     *
     * A one-off is left alone on purpose: its run_at is the whole point of the row, so a late fire on
     * the next sweep is the useful outcome. Silently dropping it would leave a row the user can only
     * cancel.
     */
    private function rearmRunAt(): bool
    {
        $now = Carbon::now();

        if (! $this->action->isRecurring() || $this->action->run_at->greaterThan($now)) {
            return false;
        }

        $this->action->run_at = Carbon::instance($this->action->nextRunAt($now));

        return true;
    }
}
