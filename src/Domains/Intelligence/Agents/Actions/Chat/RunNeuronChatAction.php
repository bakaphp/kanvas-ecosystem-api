<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Exceptions\AgentTurnCancelledException;
use Kanvas\Intelligence\Agents\Exceptions\ProviderContentBlockedException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Helpers\ConversationUsageSqlHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\Middleware\BoundToolResultsMiddleware;
use Kanvas\Intelligence\Agents\Services\AgentTurnCancellationService;
use Kanvas\Intelligence\Agents\Services\ArtifactBlockService;
use Kanvas\Intelligence\Agents\Services\AttachmentBudgetService;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Intelligence\Agents\Services\AttachmentFetchService;
use Kanvas\Intelligence\Agents\Services\NeuronResponderProviderFallback;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use Throwable;

class RunNeuronChatAction
{
    /**
     * How long a turn waits for a lease held by another run on its thread. An inbound turn typically
     * settles in well under this; the lease itself runs 600 s, far too long to hold a worker for.
     */
    private const int THREAD_BUSY_WAIT_SECONDS = 150;

    private const int THREAD_BUSY_POLL_SECONDS = 5;

    /**
     * A run whose worker died mid-turn (a deploy restart, an OOM kill) never commits again, so its lease
     * sits where the last step left it and every retry on the thread is refused until it expires. The
     * next start then supersedes it (RunsDurably starts with recoverFailed), so a turn that finds the
     * lease ending within this many seconds waits for it instead of failing.
     */
    private const int DEAD_LEASE_RECOVERY_SECONDS = 300;

    private int $threadWaitSeconds = 0;

    private bool $endedOnToolBudget = false;

    /** @var list<string> */
    private array $executedToolCalls = [];

    /**
     * @param list<string> $media Attachment URLs (image/audio/PDF/text/CSV) sent natively as content blocks.
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Users $user,
        protected readonly mixed $handler,
        protected readonly array $media = [],
        /**
         * Prose answers a failed turn only when a human reads it. A caller whose reply feeds a
         * pipeline wants the exception — the newsroom published the apology as an article once
         * already (KANVAS-ECOSYSTEM-691).
         */
        protected readonly bool $fallbackOnFailure = true,
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';

        // A caller that skips the kernel (RespondToMentionJob, DraftCustomerUpdateAction) gets the session as
        // its thread, the kernel's own choice when there is no source channel.
        if ($this->handler instanceof BehavesAsKanvasAgent && $this->handler->getThreadId() === null) {
            $this->handler->setThreadId($this->session?->uuid ?? Str::uuid()->toString());
        }

        // Agents whose chatHistory already records each turn (ConversationMessageStore) must not also
        // logTurn here — that writes a second, parallel conversation. Agents on the rollup store write
        // their history to Social messages, so they keep logTurn as their only conversation record.
        $selfRecords = $this->handler instanceof BehavesAsKanvasAgent
            && $this->handler->persistsTurnsToConversationStore();

        $allowStructuredText = ! $this->handler instanceof ConversesWithCustomer;
        $budget = new AttachmentBudgetService();

        // A stop that arrives after the turn ends must not cancel the next one, so the flag is cleared
        // on every exit; the thread is the key the cancel mutation and the middleware share.
        $threadId = $this->handler instanceof BehavesAsKanvasAgent ? $this->handler->getThreadId() : null;

        try {
            return $this->runTurn($sessionId, $selfRecords, $allowStructuredText, $budget);
        } finally {
            if ($threadId !== null) {
                AgentTurnCancellationService::clear($threadId);
            }
        }
    }

    private function runTurn(
        string $sessionId,
        bool $selfRecords,
        bool $allowStructuredText,
        AttachmentBudgetService $budget
    ): string {
        $userMessage = new UserMessage($this->message);
        foreach ($this->media as $attachment) {
            $binary = AttachmentFetchService::fetch($attachment);

            if ($binary === null) {
                $userMessage->addContent(
                    new TextContent(AttachmentFetchService::unavailableNote($attachment))
                );

                continue;
            }

            if (! $budget->admits($attachment, strlen($binary))) {
                continue;
            }

            $block = AttachmentDescriptionService::contentBlockFor($binary, allowStructuredText: $allowStructuredText);
            if ($block !== null) {
                $userMessage->addContent($block);
            }
        }

        $overBudget = $budget->skippedNote();
        if ($overBudget !== null) {
            $userMessage->addContent(new TextContent($overBudget));
        }

        $toolCalls = [];
        $toolResults = [];
        $usage = [];

        try {
            if ($this->handler instanceof BehavesAsKanvasAgent) {
                $this->handler->setAiProvider(
                    new NeuronResponderProviderFallback()->wrap(
                        $this->handler->getProvider(),
                        $this->agent,
                    ),
                );
            }

            // chat() runs the whole turn, so a provider error (e.g. Gemini blocking the content)
            // surfaces here, inside the try, and never bubbles as a 500.
            $state = $this->chatOnceTheThreadSettles($userMessage);
            $responseMessage = $state->getMessage() ?? new AssistantMessage('');
            [$toolCalls, $toolResults, $usage] = $this->extractTurnTelemetry($state, $responseMessage);
            $this->endedOnToolBudget = BoundToolResultsMiddleware::exhausted($state);
            $this->executedToolCalls = BoundToolResultsMiddleware::executedCalls($state);
        } catch (AgentTurnCancelledException $e) {
            // The person stopped it: no fallback, no report, no turn logged. The message row is kept
            // for the transcript but leaves the model's window, so the resend is answered alone.
            if ($this->handler instanceof BehavesAsKanvasAgent) {
                $this->handler->discardTurn($userMessage);
            }

            Log::info('Neuron chat turn cancelled on request', [
                'agent_id' => $this->agent->getId(),
                'session_id' => $sessionId,
                'thread_id' => $e->threadId,
            ]);

            throw $e;
        } catch (Throwable $e) {
            $fallback = $this->humanizedFallback($e);

            // Logged on both paths: report() only captures Issues, and a rethrown failure is reported
            // by a caller that no longer knows which agent, session or handler it came from.
            Log::error('Neuron chat turn failed', [
                'agent_id' => $this->agent->getId(),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->agent->companies_id,
                'users_id' => $this->user->getId(),
                'session_id' => $sessionId,
                'handler' => get_class($this->handler),
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'fallback_on_failure' => $this->fallbackOnFailure,
                'fallback' => $fallback,
            ]);

            // A failed turn is logged as one: a caller that rethrows delivers nothing, so the transcript
            // must not carry the fallback as if the agent had said it (the AP mailbox showed "I ran into a
            // hiccup" replies to emails nobody answered, KANVAS-ECOSYSTEM-6JE).
            if (! $selfRecords) {
                new KanvasConversationStore()->logTurn(
                    userId: $this->user->getId(),
                    sessionId: $sessionId,
                    agentClass: get_class($this->handler),
                    userMessage: $this->message,
                    assistantResponse: $this->fallbackOnFailure ? $fallback : '',
                    agentId: $this->agent->getId(),
                    participant: KanvasConversationStore::participantFor($this->session, $this->user, $this->agent),
                    status: AgentConversationMessage::STATUS_FAILED,
                    meta: ['error' => $e::class, 'message' => $e->getMessage()],
                );
            }

            // The caller reports what it rethrows, so the turn is never counted twice.
            if (! $this->fallbackOnFailure) {
                throw $e;
            }

            // A safety block is the provider judging the content, not a fault to fix — the person is told why instead.
            if (! $e instanceof ProviderContentBlockedException) {
                report($e);
            }

            return $fallback;
        }

        // A block the client cannot draw costs the reader the whole card, so it is removed here
        // and the prose around it kept. `render_artifact` validates what it produces, but the
        // model is free to write the fence by hand and routinely does — see stripInvalidBlocks().
        $content = new ArtifactBlockService()->stripInvalidBlocks($responseMessage->getContent() ?? '');

        if (! $selfRecords) {
            // Record the model the agent resolved to so the daily rollup can price the
            // turn — Neuron doesn't surface the model on the response itself.
            if ($this->handler instanceof BehavesAsKanvasAgent) {
                $usage['model'] = $this->handler->resolvedModelName();
            }

            new KanvasConversationStore()->logTurn(
                userId: $this->user->getId(),
                sessionId: $sessionId,
                agentClass: get_class($this->handler),
                userMessage: $this->message,
                assistantResponse: ChatHelper::extractTextFromResponse($content),
                agentId: $this->agent->getId(),
                toolCalls: $toolCalls,
                toolResults: $toolResults,
                usage: $usage,
                participant: KanvasConversationStore::participantFor($this->session, $this->user, $this->agent),
            );
        }

        // Idempotent backfill so EntityRollupMessageStore (entity-keyed query)
        // sees every channel message on the next turn.
        $this->backfillChannelMessagesToLead();

        return $content;
    }

    /**
     * True when the turn stopped because it spent its tool-output budget, not because the agent was done.
     */
    public function endedOnToolBudget(): bool
    {
        return $this->endedOnToolBudget;
    }

    /**
     * @return list<string>
     */
    public function executedToolCalls(): array
    {
        return $this->executedToolCalls;
    }

    /**
     * A durable run holds a lease on its thread while it executes, and a second inbound on the same
     * thread, two emails minutes apart on one AP mailbox, arrives while the first is still answering.
     * Neuron refuses the second with RunInFlightException (KANVAS-ECOSYSTEM-6JE). The lease is not a
     * fault: the turn waits for the thread to settle and runs then, so the second message is answered
     * with the first reply in its history instead of failing the webhook. Recovery of a dead run is
     * refused the same way while its lease is fresh, so it waits inside the same loop.
     */
    private function chatOnceTheThreadSettles(UserMessage $userMessage): AgentState
    {
        $waited = 0;

        while (true) {
            try {
                return $this->recoverOrStart($userMessage);
            } catch (RunInFlightException $e) {
                $limit = $this->leaseEndsWithin($e, self::DEAD_LEASE_RECOVERY_SECONDS)
                    ? self::THREAD_BUSY_WAIT_SECONDS + self::DEAD_LEASE_RECOVERY_SECONDS
                    : self::THREAD_BUSY_WAIT_SECONDS;

                if ($waited >= $limit) {
                    throw $e;
                }

                if ($waited === 0) {
                    Log::info('Agent thread busy; waiting for the running turn to settle', [
                        'agent_id' => $this->agent->getId(),
                        'thread_id' => $e->workflowId,
                        'run_id' => $e->runId,
                    ]);
                }

                $this->pause(self::THREAD_BUSY_POLL_SECONDS);
                $waited += self::THREAD_BUSY_POLL_SECONDS;
                $this->threadWaitSeconds = $waited;
            }
        }
    }

    /**
     * A redelivered turn whose first attempt died with its worker is continued, not restarted, so no
     * write runs twice.
     */
    private function recoverOrStart(UserMessage $userMessage): AgentState
    {
        $recovered = $this->handler instanceof BehavesAsKanvasAgent
            ? $this->handler->recoverInterruptedRun($userMessage)
            : null;

        return $recovered ?? $this->handler->chat($userMessage);
    }

    private function leaseEndsWithin(RunInFlightException $e, int $seconds): bool
    {
        return $e->leaseExpiresAt !== null && $e->leaseExpiresAt - time() <= $seconds;
    }

    /**
     * Time spent waiting for another run's lease, kept apart so the turn metric measures the agent and
     * not the lock.
     */
    public function threadWaitMs(): int
    {
        return $this->threadWaitSeconds * 1000;
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    private function humanizedFallback(Throwable $e): string
    {
        // A prospect talks to a persona (Sales, Receptionist): any system wording — a hiccup, an
        // overloaded AI, a safety filter — breaks it. The real cause is still logged and reported.
        if ($this->agent->conversesWithCustomer()) {
            return 'What do you mean?';
        }

        if ($this->isDuplicateEntryError($e)) {
            return "It looks like that already exists — I didn't create a duplicate. Let me know if you'd "
                . 'like me to look into it or handle it a different way.';
        }

        // Any tool can trip the run budget, not just the people lookups — a reporting tool looping on an
        // empty date range hits it too (KANVAS-ECOSYSTEM-682).
        if ($e instanceof ToolRunsExceededException) {
            return 'I kept retrying the same lookup without getting anywhere. Could you narrow it down for me — '
                . 'an exact name, email, or date range — and ask again?';
        }

        // Rephrasing doesn't help: the blocked content stays in the history and every later turn is refused too.
        if ($e instanceof ProviderContentBlockedException) {
            return "I can't respond to this conversation — the AI provider's content safety filter blocked it. "
                . 'This is an automatic check on their side, so sending it again will be blocked too. '
                . 'Starting a new conversation without the flagged content should work.';
        }

        // Nothing about the request was wrong, so telling the person to rephrase sends them to fix
        // something that is not broken — and inviting a hand-off escalates a wait to a human. The
        // model was busy; the only useful instruction is to ask again.
        if ($this->isProviderOverloaded($e)) {
            return 'The AI service is overloaded right now — that is on their side, not yours. '
                . 'Ask me the same thing again in a moment and it should go through.';
        }

        return 'I ran into a hiccup processing that. Could you try rephrasing, '
            . 'or let me know if you want me to hand off to a human?';
    }

    /**
     * A transient fault at the model provider rather than a fault in the request.
     *
     * Keyed on the HTTP status, because the provider's prose varies and is truncated by the time it
     * reaches a log ("This model is currently experiencing high demand"). 429 is a rate limit; every
     * 5xx covers the rest — Gemini's 503 and Anthropic's 529 alike — and the same request would
     * survive a retry in all of them.
     */
    private function isProviderOverloaded(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof RequestException || ! $current->hasResponse()) {
                continue;
            }

            $status = $current->getResponse()->getStatusCode();

            if ($status === 429 || $status >= 500) {
                return true;
            }
        }

        return false;
    }

    private function isDuplicateEntryError(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    private function backfillChannelMessagesToLead(): void
    {
        if ($this->session === null) {
            return;
        }

        $freshSession = $this->session->fresh();
        if ($freshSession === null || $freshSession->entity_namespace !== Lead::class || $freshSession->entity_id === null) {
            return;
        }

        $lead = Lead::find($freshSession->entity_id);
        $channel = $freshSession->channel;

        if ($lead === null || $channel === null) {
            return;
        }

        $people = $lead->people;
        $peopleChannelService = $people !== null ? new PeopleChannelService() : null;
        $leadChannelService = new LeadChannelService();

        foreach ($channel->messages()->get() as $message) {
            $leadChannelService->attachMessageToLeadChannel(
                $message,
                $lead,
                $lead->app,
                $lead->company,
                $this->user,
            );

            if ($peopleChannelService !== null && $people !== null) {
                $peopleChannelService->attachMessageToPeopleChannel(
                    $message,
                    $people,
                    $lead->app,
                    $lead->company,
                    $this->user,
                );
            }
        }
    }

    /**
     * Walk this turn's intermediate steps (tool calls + their results) plus the
     * final assistant message, returning aggregated telemetry for persistence.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, int>}
     */
    private function extractTurnTelemetry(AgentState $state, Message $finalMessage): array
    {
        $toolCalls = [];
        $toolResults = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $seenFinal = false;

        $accumulate = function (Message $m) use (&$toolCalls, &$toolResults, &$usage): void {
            if ($m instanceof ToolCallMessage) {
                foreach ($m->getToolCalls() as $call) {
                    $toolCalls[] = $call->jsonSerialize();
                }
            }
            if ($m instanceof ToolResultMessage) {
                foreach ($m->getToolCalls() as $call) {
                    $toolResults[] = $call->jsonSerialize();
                }
            }
            foreach (ConversationUsageSqlHelper::neuronUsageRow($m) as $key => $count) {
                $usage[$key] += $count;
            }
        };

        foreach ($state->getSteps() as $step) {
            $accumulate($step);
            if ($step === $finalMessage) {
                $seenFinal = true;
            }
        }

        if (! $seenFinal) {
            $accumulate($finalMessage);
        }

        return [$toolCalls, $toolResults, array_filter($usage)];
    }
}
