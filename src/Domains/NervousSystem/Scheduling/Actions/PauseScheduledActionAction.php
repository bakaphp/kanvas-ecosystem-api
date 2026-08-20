<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;

class PauseScheduledActionAction
{
    public function __construct(
        protected readonly ScheduledAction $action,
    ) {
    }

    public function execute(): ScheduledAction
    {
        $status = ScheduledActionStatusEnum::from($this->action->status);

        if ($status === ScheduledActionStatusEnum::PAUSED) {
            return $this->action;
        }

        // EXECUTING is mid-fire and already claimed by a sweeper — pausing it would race the worker
        // that owns the row. Terminal rows have nothing left to pause.
        if ($status !== ScheduledActionStatusEnum::PENDING) {
            throw new ValidationException(
                "A scheduled action in status '{$status->value}' cannot be paused.",
            );
        }

        $this->action->status = ScheduledActionStatusEnum::PAUSED->value;
        $this->action->saveOrFail();

        $this->action->emitLedgerEvent('scheduled_action.paused', payload: [
            'action_type' => $this->action->action_type,
            'recurring' => $this->action->isRecurring(),
            'run_at' => $this->action->run_at->toIso8601String(),
        ]);

        return $this->action;
    }
}
