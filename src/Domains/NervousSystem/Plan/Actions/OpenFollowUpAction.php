<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

/**
 * The wait an agent opens when it asks a teammate for something on a person's behalf: one plan with
 * one task, parked on the teammate until the date they gave, owned by whoever asked. Without it the
 * agent says "I'll let you know" and has nothing that could ever let anyone know.
 *
 * Same shape as a coding job — plan + single task + origin — so closing the task closes the plan and
 * the completed-plan alert reaches the requester through the paths that already exist.
 */
class OpenFollowUpAction
{
    public const string PLAN_TYPE = 'follow_up';

    public function __construct(
        private readonly Agent $agent,
        /** Null when nobody in particular owes it, or the person asked them outside the agent. */
        private readonly ?Users $recipient,
        private readonly string $waitingFor,
        private readonly Carbon $until,
        private readonly Users $requestedBy,
        private readonly ?Session $session = null,
        private readonly ?int $channelId = null,
    ) {
    }

    public function execute(): Task
    {
        $name = $this->recipientName();

        $task = new CreateAgentRunPlanAction(
            agent: $this->agent,
            brief: $name === null ? sprintf('Get %s', $this->waitingFor) : sprintf('Get %s from %s', $this->waitingFor, $name),
            planType: self::PLAN_TYPE,
            requestedBy: $this->requestedBy,
            titlePrefix: 'Follow-up: ',
            session: $this->session,
        )->execute();

        if ($this->recipient !== null) {
            new RecordTaskAskAction($task, $this->recipient, $this->channelId)->execute();
        }

        new SnoozeTaskAction(
            task: $task,
            agent: $this->agent,
            until: $this->until,
            reason: $name === null ? sprintf('Waiting for %s', $this->waitingFor) : sprintf('%s: %s', $name, $this->waitingFor),
            session: $this->session,
        )->execute();

        return $task->refresh();
    }

    private function recipientName(): ?string
    {
        if ($this->recipient === null) {
            return null;
        }

        return trim((string) $this->recipient->firstname . ' ' . (string) $this->recipient->lastname) ?: $this->recipient->email;
    }
}
