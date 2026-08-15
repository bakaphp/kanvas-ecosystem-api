<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;

class CancelScheduledActionAction
{
    public function __construct(
        protected readonly ScheduledAction $action,
    ) {
    }

    public function execute(): ScheduledAction
    {
        if (in_array(
            ScheduledActionStatusEnum::from($this->action->status),
            ScheduledActionStatusEnum::terminalStatuses(),
            true,
        )) {
            return $this->action;
        }

        $this->action->status = ScheduledActionStatusEnum::CANCELLED->value;
        $this->action->saveOrFail();

        $this->action->emitLedgerEvent('scheduled_action.cancelled', payload: [
            'action_type' => $this->action->action_type,
            'recurring' => $this->action->isRecurring(),
        ]);

        return $this->action;
    }
}
