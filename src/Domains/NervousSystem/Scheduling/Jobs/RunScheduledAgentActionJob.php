<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Chat\WakeAgentInSessionAction;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentTurnResponse;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Actions\DeliverScheduledMessageToChannelAction;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Notifications\ScheduledReminderNotification;
use Kanvas\Users\Models\Users;
use NeuronAI\Exceptions\RunInFlightException;
use Throwable;

/**
 * Fires a single claimed (status=executing) scheduled action, then re-arms it if recurring or marks it
 * done if one-off. A `reminder` delivers a stored message with no LLM; an `agent_task` wakes the agent's
 * runtime with the stored instruction so it acts with its full toolset.
 */
class RunScheduledAgentActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    /** The only statuses a claimed row may still be in when the job runs; anything else is not ours. */
    private const array RUNNABLE_STATUSES = [
        ScheduledActionStatusEnum::PENDING,
        ScheduledActionStatusEnum::EXECUTING,
    ];

    /**
     * @param string|null $claim the `claimed_at` the sweeper stamped when it handed this row to the job,
     *                           as a plain string: `SerializesModels` reloads `$action` when the job runs,
     *                           so reading `claimed_at` off the model would show whatever claim is newest.
     */
    public function __construct(
        public readonly Apps $app,
        public readonly ?Companies $company,
        public readonly ScheduledAction $action,
        public readonly ?string $claim = null,
    ) {
        $this->onQueue('scheduled-actions');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if (! $this->stillRunnable()) {
            return;
        }

        try {
            match (ScheduledActionTypeEnum::from($this->action->action_type)) {
                ScheduledActionTypeEnum::REMINDER => $this->deliverReminder(),
                ScheduledActionTypeEnum::AGENT_TASK => $this->wakeAgent(),
            };
        } catch (RunInFlightException $e) {
            // The agent is mid-turn in this thread (a person is talking to it). The task has not run, so
            // it neither counts as fired nor fails: it runs once the thread settles.
            Log::info('Scheduled agent task deferred; the thread is busy', [
                'scheduled_action_id' => $this->action->getId(),
                'run_id' => $e->runId,
            ]);
            $this->release($this->backoff);

            return;
        } catch (AgentReplySkippedException) {
            // Deliberately falls through to re-arm: a deactivated agent should leave its schedule
            // ticking harmlessly, so reactivating it resumes the cadence instead of stranding the
            // row on a missed slot.
        }

        $this->reArmOrComplete();
    }

    /**
     * A cancel can land after the sweeper claimed the row and before, or while, this job runs. Re-read
     * the row rather than trust the one the job was built with, and refuse a row that is cancelled,
     * deleted, already terminal, or claimed again since (a stale-claim reclaim handed it to a newer job).
     */
    private function stillRunnable(): bool
    {
        $this->action->refresh();

        $status = ScheduledActionStatusEnum::from($this->action->status);
        $claimed = $this->action->claimed_at?->toDateTimeString();

        $runnable = ! (bool) $this->action->is_deleted
            && in_array($status, self::RUNNABLE_STATUSES, true)
            && ($this->claim === null || $claimed === $this->claim);

        if (! $runnable) {
            Log::info('Scheduled action skipped; no longer runnable', [
                'scheduled_action_id' => $this->action->getId(),
                'status' => $status->value,
                'claim' => $this->claim,
                'claimed_at' => $claimed,
            ]);
        }

        return $runnable;
    }

    /**
     * The post-run write, conditional on the row still being ours. A plain `save()` would resurrect a
     * row cancelled during the turn (status back to `executed`/`pending`) and re-arm a cancelled
     * recurrence; zero affected rows means "cancelled or re-claimed meanwhile" and nothing is written.
     *
     * @param array<string, mixed> $attributes
     */
    private function commit(array $attributes): bool
    {
        $query = ScheduledAction::query()
            ->whereKey($this->action->getKey())
            ->where('is_deleted', 0)
            ->whereIn('status', array_map(static fn (ScheduledActionStatusEnum $status): string => $status->value, self::RUNNABLE_STATUSES));

        if ($this->claim !== null) {
            $query->where('claimed_at', $this->claim);
        }

        $written = $query->update($attributes) === 1;

        if (! $written) {
            Log::info('Scheduled action outcome not recorded; the row was cancelled or re-claimed meanwhile', [
                'scheduled_action_id' => $this->action->getId(),
                'claim' => $this->claim,
            ]);
        }

        return $written;
    }

    private function deliverReminder(): void
    {
        $message = (string) ($this->action->payload['message'] ?? '');
        $session = $this->resolveSessionByUuid();
        $channel = $session?->channel;

        // Last look before the irreversible part: a message already sent cannot be recalled.
        if (! $this->stillRunnable()) {
            return;
        }

        // Deliver into the channel when there is one; if nothing went out natively (no channel, or one we
        // can't push to) fall back to the notification so the recipient is still reached.
        $nativelyDelivered = false;
        if ($channel !== null) {
            $nativelyDelivered = new DeliverScheduledMessageToChannelAction(
                channel: $channel,
                text: $message,
                author: $this->action->agent?->user ?? $this->action->recipient,
                agent: $this->action->agent,
                sessionUuid: $this->action->session_uuid,
                canalId: $session?->canal_id,
            )->execute();
        }

        if (! $nativelyDelivered) {
            /** @var Users $recipient */
            $recipient = $this->action->recipient;
            $recipient->notify(new ScheduledReminderNotification($this->action, $message));
        }

        $this->action->emitLedgerEvent('scheduled_action.fired', payload: [
            'action_type' => $this->action->action_type,
            'users_id' => $this->action->users_id,
            'via' => $nativelyDelivered ? 'channel' : 'notification',
        ]);
    }

    private function wakeAgent(): void
    {
        $agent = $this->action->agent;
        if ($agent === null) {
            throw new ValidationException('A scheduled agent task has no agent to run.');
        }

        $this->reopenWaitingTask();

        $response = new WakeAgentInSessionAction(
            agent: $agent,
            session: $this->resolveSession($agent),
            instruction: self::assignment((string) ($this->action->payload['instruction'] ?? '')),
            user: $this->action->recipient ?? $agent->user,
        )->execute();

        $this->action->emitLedgerEvent('scheduled_action.fired', payload: [
            'action_type' => $this->action->action_type,
            'response_length' => strlen($response),
        ]);
    }

    /**
     * The wake of a snoozed task ends the wait: the task is back in progress before the agent runs, so
     * the continuation loop counts it as open again instead of answering WAIT to the agent's own wake.
     */
    private function reopenWaitingTask(): void
    {
        if ($this->action->source_entity_type !== Task::class) {
            return;
        }

        $task = Task::query()
            ->where('id', (int) $this->action->source_entity_id)
            ->where('apps_id', $this->action->apps_id)
            ->notDeleted()
            ->first();

        if ($task === null || $task->status !== TaskStatusEnum::WAITING->value) {
            return;
        }

        new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::IN_PROGRESS,
            fromSync: true,
            firingWakeId: $this->action->getId(),
        )->execute();
    }

    /**
     * Reached as a bare user turn, a recurring task reads like a check-in, and an agent told to stay quiet
     * when nothing changed answers NO_UPDATE instead of the summary it was asked for (Roven, task 168).
     */
    private static function assignment(string $instruction): string
    {
        return 'Scheduled task: you were asked to do this at this time. Carry it out now and post the result, '
            . 'even if it looks like the last run. Do not reply ' . AgentTurnResponse::NO_UPDATE . ".\n\n"
            . 'Task: ' . $instruction;
    }

    private function resolveSessionByUuid(): ?Session
    {
        if ($this->action->session_uuid === null) {
            return null;
        }

        /** @var Session|null $session */
        $session = Session::query()
            ->where('uuid', $this->action->session_uuid)
            ->when(
                $this->action->agent,
                fn (Builder $query, Agent $agent): Builder => $query->fromAgent($agent),
                fn (Builder $query): Builder => $query->latest('id')
            )
            ->first();

        return $session;
    }

    private function resolveSession(Agent $agent): Session
    {
        if ($this->action->session_uuid !== null) {
            /** @var Session|null $existing */
            $existing = Session::query()
                ->fromAgent($agent)
                ->where('uuid', $this->action->session_uuid)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $this->action->apps_id,
                'companies_id' => $this->action->companies_id,
                'entity_namespace' => ScheduledAction::class,
                'entity_id' => $this->action->getKey(),
                'agents_id' => $agent->getId(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'channel_id' => null,
                'content' => '',
                'user' => [],
            ],
        );

        return $session;
    }

    /**
     * The one place one-off and recurring diverge. A one-off is done. A recurring row re-arms its own
     * `run_at` to the next FUTURE occurrence (missed slots are skipped — no catch-up storm) unless it has
     * hit its end date or max occurrences, in which case the series is complete.
     */
    private function reArmOrComplete(): void
    {
        $occurrences = (int) $this->action->occurrences_count + 1;
        $attributes = [
            'occurrences_count' => $occurrences,
            'last_fired_at' => Carbon::now(),
            'last_error' => null,
            'claimed_at' => null,
        ];

        if (! $this->action->isRecurring()) {
            $this->commit([
                ...$attributes,
                'status' => ScheduledActionStatusEnum::EXECUTED->value,
                'executed_at' => Carbon::now(),
            ]);

            return;
        }

        $next = $this->action->nextRunAt(Carbon::now());

        $ended = ($this->action->recurrence_ends_at !== null
                && $next->greaterThan($this->action->recurrence_ends_at))
            || $this->action->occurrenceCapReached($occurrences);

        $this->commit($ended
            ? [...$attributes, 'status' => ScheduledActionStatusEnum::COMPLETED->value]
            : [
                ...$attributes,
                'run_at' => Carbon::instance($next),
                'status' => ScheduledActionStatusEnum::PENDING->value,
                'attempts' => 0,
            ]);
    }

    /**
     * Final failure after retries: record the fault. A recurring schedule still re-arms to its next
     * occurrence (one bad run shouldn't kill a standing schedule); a one-off is marked failed.
     */
    public function failed(Throwable $exception): void
    {
        $attributes = [
            'attempts' => (int) $this->action->attempts + 1,
            'last_error' => Str::limit($exception->getMessage(), 2000),
            'claimed_at' => null,
        ];

        $written = $this->commit($this->action->isRecurring()
            ? [
                ...$attributes,
                'run_at' => Carbon::instance($this->action->nextRunAt(Carbon::now())),
                'status' => ScheduledActionStatusEnum::PENDING->value,
            ]
            : [...$attributes, 'status' => ScheduledActionStatusEnum::FAILED->value]);

        if (! $written) {
            return;
        }

        $this->action->emitLedgerEvent(
            'scheduled_action.failed',
            status: EventStatusEnum::ERROR,
            payload: ['action_type' => $this->action->action_type],
            error: ['message' => $exception->getMessage(), 'class' => $exception::class],
        );
    }
}
