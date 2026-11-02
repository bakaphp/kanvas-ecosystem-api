<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Collection;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

/**
 * Records who a task is waiting on and where it asked them. Written when the ask goes out, not when
 * the task is snoozed, because the two happen in different tool calls and the model forgets the
 * second half: a task parked "waiting on Charlie" with no Charlie on it is what made every reply
 * from Charlie arrive as a stranger's message.
 *
 * Returns the agent's other open tasks already waiting on the same person — the duplicate-ask
 * signal: one message listing everything open with them beats two, and the same requester asking
 * twice gets the existing task's status rather than a second task.
 */
class RecordTaskAskAction
{
    public function __construct(
        private readonly Task $task,
        private readonly Users $person,
        private readonly ?int $channelId = null,
    ) {
    }

    /**
     * @return Collection<int, Task>
     */
    public function execute(): Collection
    {
        $this->task->waiting_on_users_id = $this->person->getId();

        if ($this->channelId !== null) {
            $this->task->waiting_on_channel_id = $this->channelId;
        }

        $this->task->saveQuietly();

        $this->task->emitLedgerEvent('plan.task.ask_sent', payload: [
            'plan_id' => $this->task->plan_id,
            'waiting_on_users_id' => $this->person->getId(),
            'waiting_on_channel_id' => $this->task->waiting_on_channel_id,
        ]);

        return Task::query()
            ->where('apps_id', $this->task->apps_id)
            ->where('companies_id', $this->task->companies_id)
            ->whereKeyNot($this->task->getId())
            ->where('waiting_on_users_id', $this->person->getId())
            ->waiting()
            ->orderBy('id')
            ->get();
    }
}
