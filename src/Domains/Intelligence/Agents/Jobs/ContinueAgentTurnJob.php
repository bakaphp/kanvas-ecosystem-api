<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Scheduling\Actions\DeliverScheduledMessageToChannelAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * Runs the turn a person would otherwise have started by typing "continue".
 *
 * A turn that spends its tool-output budget stops mid-batch and reports what is done and what is left.
 * That report is the whole hand-off: a new turn never replays the old one's raw tool results, so it
 * starts small again with a fresh budget, the same as when a person types "continue".
 *
 * Each continuation is its own job rather than a loop inside one worker, so a long batch cannot run
 * into a queue timeout and a person's reply is noticed between turns.
 */
class ContinueAgentTurnJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /** Bounds a batch that never finishes — each continuation is a full paid turn. */
    public const int MAX_CONTINUATIONS = 4;

    /** Never retried: a turn's tool calls have real side effects, and a retry would repeat them. */
    public int $tries = 1;

    /**
     * @param list<string> $executedCalls Every call already run for this request, as `name:sha1(inputs)`.
     * @param string $since When the request's first reply went out; a person writing after it wins.
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly Session $session,
        public readonly Users $user,
        public readonly string $previousReply,
        public readonly int $continuation,
        public readonly array $executedCalls,
        public readonly string $since,
    ) {
        $this->onQueue('agent-chat');
    }

    /**
     * Queue the first follow-up when a turn was cut short by its tool-output budget, so a long batch
     * finishes without someone typing "continue". Call it only after the reply is delivered — the
     * follow-up must land after it. Internal agents only: on a customer surface a stranger could turn one
     * message into several paid turns.
     */
    public static function dispatchIfCutShort(AgentChatKernel $kernel, string $reply): void
    {
        $session = $kernel->session();

        if ($session === null || ! $kernel->endedOnToolBudget() || ! $kernel->agent()->conversesWithUser()) {
            return;
        }

        self::dispatch(
            agent: $kernel->agent(),
            session: $session,
            user: $kernel->user(),
            previousReply: ChatHelper::extractTextFromResponse($reply),
            continuation: 1,
            executedCalls: $kernel->executedToolCalls(),
            since: now()->toIso8601String(),
        );
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->agent->app);

        $channel = $this->session->channel;

        if ($channel === null || $this->personHasReplied($channel)) {
            return;
        }

        $kernel = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $this->prompt(),
            user: $this->user,
            sourceChannel: $channel,
            persistConversation: false,
            privateUserTurn: true,
        );

        try {
            // Extracted like every other surface does before posting — an agent can answer with a JSON
            // envelope, and the raw envelope would otherwise land in the Slack thread.
            $reply = ChatHelper::extractTextFromResponse($kernel->execute());
        } catch (AgentReplySkippedException) {
            return;
        }

        if (trim($reply) !== '') {
            new DeliverScheduledMessageToChannelAction(
                channel: $channel,
                text: $reply,
                author: $this->agent->user ?? $this->agent->company->getAiAgentUserOrFail(),
                agent: $this->agent,
                sessionUuid: $this->session->uuid,
                canalId: $this->session->canal_id,
                verb: 'agent-continuation',
                fromAgentTurn: true,
            )->execute();
        }

        $this->continueIfStillCutShort($kernel, $reply);
    }

    /**
     * The last report goes in the prompt rather than being left to history: which history an agent reads
     * depends on its handler and surface, and some only see a reply once the connector has persisted it.
     */
    private function prompt(): string
    {
        return 'Continue the task from your last report. It stopped because that turn ran out of tool-output '
            . 'budget, not because it was finished. Do only what is still left, and do not repeat anything '
            . "already done.\n\nYour last report:\n" . $this->previousReply;
    }

    /**
     * A turn that ran only calls already made has not moved the batch forward; stopping there keeps a
     * stuck agent from spending the rest of the cap on the same work.
     */
    private function continueIfStillCutShort(AgentChatKernel $kernel, string $reply): void
    {
        if (! $kernel->endedOnToolBudget() || $this->continuation >= self::MAX_CONTINUATIONS) {
            return;
        }

        $newCalls = array_diff($kernel->executedToolCalls(), $this->executedCalls);

        if ($newCalls === []) {
            return;
        }

        self::dispatch(
            agent: $this->agent,
            session: $this->session,
            user: $this->user,
            previousReply: $reply,
            continuation: $this->continuation + 1,
            executedCalls: array_values([...$this->executedCalls, ...$newCalls]),
            since: $this->since,
        );
    }

    /**
     * Filtered in PHP rather than with a JSON-path where: a person's message may carry no `from_ia` key
     * at all, and a JSON "does not contain" treats a missing key as NULL and drops the very row we want.
     */
    private function personHasReplied(Channel $channel): bool
    {
        return $channel->messages()
            ->where('messages.created_at', '>', Carbon::parse($this->since))
            ->get(['messages.message'])
            ->contains(static fn (Message $message): bool => ($message->getMessage()['from_ia'] ?? false) !== true);
    }
}
