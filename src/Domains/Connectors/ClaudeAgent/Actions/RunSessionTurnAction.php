<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\DrainOutcomeEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Connectors\ClaudeAgent\Services\CustomToolBridgeService;
use Kanvas\Connectors\ClaudeAgent\Services\EventDrainService;
use Kanvas\Connectors\ClaudeAgent\Traits\ReportsAndContinues;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;

/**
 * One conversational turn against a hosted agent: ensure the remote agent and environment exist,
 * open or resume the session, send the message, drain events until the turn finishes.
 *
 * The first two steps are idempotent and normally cost no HTTP at all — the environment id is cached
 * on the company and the agent definition is skipped when its fingerprint is unchanged.
 */
class RunSessionTurnAction
{
    use ReportsAndContinues;
    use ResolvesClaudeClient;

    /** A turn still calling tools after this many round-trips is looping. */
    public const int MAX_TOOL_ROUNDS = 12;

    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly array $images = [],
        protected readonly ?Client $client = null,
        protected readonly int $deadlineMs = EventDrainService::DEFAULT_DEADLINE_MS,
        protected readonly int $pollIntervalMs = EventDrainService::DEFAULT_POLL_INTERVAL_MS,
        protected readonly ?CustomToolBridgeService $bridge = null,
    ) {
    }

    public function execute(): string
    {
        $app = $this->agent->app;
        $company = $this->agent->company;
        $client = $this->claudeClient($app, $company);

        $environmentId = new EnsureEnvironmentAction($app, $company, $client)->execute();
        $remoteAgent = new PushAgentDefinitionAction($this->agent, $client)->execute();

        $sessionId = new OpenSessionAction(
            agent: $this->agent,
            session: $this->session,
            environmentId: $environmentId,
            remoteAgentId: $remoteAgent['id'],
            remoteAgentVersion: $remoteAgent['version'],
            client: $client,
        )->execute();

        $client->sendEvents($sessionId, [
            [
                'type' => 'user.message',
                'content' => [['type' => 'text', 'text' => $this->composeMessage()]],
            ],
        ]);

        return $this->drainServingTools($client, $sessionId);
    }

    /**
     * Drain, and whenever the session blocks on a Kanvas tool, run it and hand the result back so
     * the agent can continue. One logical turn can therefore span several drains.
     *
     * The deadline is global, not per drain: each pass gets only the time left, so a tool-heavy
     * turn can't quietly run for rounds × deadline.
     */
    protected function drainServingTools(Client $client, string $sessionId): string
    {
        $bridge = $this->bridge ?? new CustomToolBridgeService($this->agent);
        $endsAt = hrtime(true) + ($this->deadlineMs * 1_000_000);
        $collected = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $remainingMs = (int) max(0, (int) (($endsAt - hrtime(true)) / 1_000_000));

            $result = new EventDrainService(
                $client,
                $sessionId,
                OpenSessionAction::storedCursor($this->session),
                $remainingMs,
                $this->pollIntervalMs,
            )->drain();

            OpenSessionAction::storeCursor($this->session, $result->cursor);
            // Every pass, not just the terminal one, so a long tool-heavy turn shows its spend while
            // it is still running. The figure is cumulative, so re-recording it costs nothing.
            $this->bestEffort(fn () => new RecordSessionUsageAction($this->agent, $sessionId, $result->usage)->execute());

            if ($result->text !== '') {
                $collected[] = $result->text;
            }

            if ($result->outcome !== DrainOutcomeEnum::AWAITING_CLIENT) {
                $reply = $this->present(implode("\n\n", $collected), $result->outcome, $result->stopReason);

                return $reply . $this->attachOutputs($client, $sessionId);
            }

            // Blocked on something we can't answer — a tool confirmation, or a tool the model
            // invented. Looping would spin to the deadline and then report a timeout, hiding the
            // real cause, so stop and say what happened.
            if ($result->pendingToolCalls === []) {
                throw new ClaudeAgentApiException(
                    'The hosted agent is waiting on a client action that is not supported '
                    . '(no matching Kanvas tool call was found).',
                    0,
                );
            }

            $client->sendEvents($sessionId, $bridge->resultEvents($result->pendingToolCalls));
        }

        // Ran out of rounds with the agent still calling tools. Report the partial rather than
        // presenting it as a finished answer.
        return $this->present(implode("\n\n", $collected), DrainOutcomeEnum::TIMED_OUT, null);
    }

    /**
     * Attached to the Kanvas Session rather than a Plan, since a conversational turn has no plan,
     * and named in the reply so the user knows the files exist at all.
     */
    protected function attachOutputs(Client $client, string $sessionId): string
    {
        $attached = [];
        $this->bestEffort(function () use ($client, $sessionId, &$attached): void {
            $attached = new PullSessionOutputsAction(
                $this->session,
                $this->agent->user,
                $sessionId,
                $client,
            )->execute();
        });

        return $attached === []
            ? ''
            : "\n\nAttached to this conversation: " . implode(', ', $attached);
    }

    /**
     * Image URLs ride in the text rather than as native image content blocks — the session event
     * schema for non-text content isn't verified yet, and a wrong block shape would fail the whole
     * turn. Revisit once we've confirmed it against a live session.
     */
    protected function composeMessage(): string
    {
        if ($this->images === []) {
            return $this->message;
        }

        return $this->message . "\n\nAttached images:\n" . implode("\n", $this->images);
    }

    /**
     * Never return a bare empty string on a non-successful outcome — an empty reply reads to every
     * caller as "the agent answered with nothing" rather than "the agent did not finish", which is
     * exactly the silent-success failure this connector is built to avoid.
     */
    protected function present(string $text, DrainOutcomeEnum $outcome, ?string $stopReason): string
    {
        if ($outcome->isSuccessful()) {
            return $text;
        }

        $note = match ($outcome) {
            DrainOutcomeEnum::TIMED_OUT => 'The agent is still working on this. Ask again in a moment to pick up where it left off.',
            DrainOutcomeEnum::BUDGET_REACHED => 'The agent paused because this session reached its spend limit. Raise or remove the budget to continue.',
            DrainOutcomeEnum::FAILED => $stopReason !== null
                ? "The agent did not complete this turn (stop reason: {$stopReason})."
                : 'The agent did not complete this turn.',
            DrainOutcomeEnum::TERMINATED => 'This session has ended.',
            default => 'The agent did not complete this turn.',
        };

        return $text !== '' ? $text . "\n\n" . $note : $note;
    }
}
