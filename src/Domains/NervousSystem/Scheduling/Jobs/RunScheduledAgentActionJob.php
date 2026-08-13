<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Scheduling\Actions\DeliverScheduledMessageToChannelAction;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Notifications\ScheduledReminderNotification;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
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

    public function __construct(
        public readonly Apps $app,
        public readonly ?Companies $company,
        public readonly ScheduledAction $action,
    ) {
        $this->onQueue('scheduled-actions');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        match (ScheduledActionTypeEnum::from($this->action->action_type)) {
            ScheduledActionTypeEnum::REMINDER => $this->deliverReminder(),
            ScheduledActionTypeEnum::AGENT_TASK => $this->wakeAgent(),
        };

        $this->reArmOrComplete();
    }

    private function deliverReminder(): void
    {
        $message = (string) ($this->action->payload['message'] ?? '');
        $channel = $this->resolveChannel();

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

        $session = $this->resolveSession($agent);
        $instruction = (string) ($this->action->payload['instruction'] ?? '');

        // sourceChannel MUST be passed when the session has one — otherwise the kernel activates
        // setThreadId and the agent loses its cross-session history on this cron-spawned wake.
        // privateUserTurn: the wake instruction is a USER turn only to drive the agent — no one typed it.
        $response = new AgentChatKernel(
            agent: $agent,
            session: $session,
            message: $instruction,
            user: $this->action->recipient ?? $agent->user,
            sourceChannel: $session->channel,
            persistConversation: false,
            privateUserTurn: true,
        )->execute();

        // Post what the agent did back into the conversation so the user sees the outcome.
        if ($session->channel !== null && trim($response) !== '') {
            new DeliverScheduledMessageToChannelAction(
                channel: $session->channel,
                text: $response,
                author: $agent->user,
                agent: $agent,
                verb: 'scheduled-agent-reply',
            )->execute();
        }

        $this->action->emitLedgerEvent('scheduled_action.fired', payload: [
            'action_type' => $this->action->action_type,
            'response_length' => strlen($response),
        ]);
    }

    private function resolveChannel(): ?Channel
    {
        if ($this->action->session_uuid === null) {
            return null;
        }

        /** @var Session|null $session */
        $session = Session::query()->where('uuid', $this->action->session_uuid)->first();

        return $session?->channel;
    }

    private function resolveSession(Agent $agent): Session
    {
        if ($this->action->session_uuid !== null) {
            /** @var Session|null $existing */
            $existing = Session::query()->where('uuid', $this->action->session_uuid)->first();
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
        $this->action->occurrences_count = (int) $this->action->occurrences_count + 1;
        $this->action->last_fired_at = Carbon::now();
        $this->action->last_error = null;

        if (! $this->action->isRecurring()) {
            $this->action->status = ScheduledActionStatusEnum::EXECUTED->value;
            $this->action->executed_at = Carbon::now();
            $this->action->claimed_at = null;
            $this->action->saveOrFail();

            return;
        }

        $next = $this->action->nextRunAt(Carbon::now());

        $ended = ($this->action->recurrence_ends_at !== null
                && $next->greaterThan($this->action->recurrence_ends_at))
            || ($this->action->max_occurrences !== null
                && $this->action->occurrences_count >= $this->action->max_occurrences);

        if ($ended) {
            $this->action->status = ScheduledActionStatusEnum::COMPLETED->value;
            $this->action->claimed_at = null;
        } else {
            $this->action->run_at = Carbon::instance($next);
            $this->action->status = ScheduledActionStatusEnum::PENDING->value;
            $this->action->claimed_at = null;
            $this->action->attempts = 0;
        }

        $this->action->saveOrFail();
    }

    /**
     * Final failure after retries: record the fault. A recurring schedule still re-arms to its next
     * occurrence (one bad run shouldn't kill a standing schedule); a one-off is marked failed.
     */
    public function failed(Throwable $exception): void
    {
        $this->action->attempts = (int) $this->action->attempts + 1;
        $this->action->last_error = Str::limit($exception->getMessage(), 2000);

        if ($this->action->isRecurring()) {
            $next = $this->action->nextRunAt(Carbon::now());
            $this->action->run_at = Carbon::instance($next);
            $this->action->status = ScheduledActionStatusEnum::PENDING->value;
            $this->action->claimed_at = null;
        } else {
            $this->action->status = ScheduledActionStatusEnum::FAILED->value;
            $this->action->claimed_at = null;
        }

        $this->action->saveOrFail();

        $this->action->emitLedgerEvent(
            'scheduled_action.failed',
            status: EventStatusEnum::ERROR,
            payload: ['action_type' => $this->action->action_type],
            error: ['message' => $exception->getMessage(), 'class' => $exception::class],
        );
    }
}
