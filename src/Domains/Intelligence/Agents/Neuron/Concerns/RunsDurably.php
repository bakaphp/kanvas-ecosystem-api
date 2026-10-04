<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\RedisPersistence;
use NeuronAI\Workflow\WorkflowStatus;
use Override;
use Redis;

/**
 * The run kept outside the worker, and the turn a dead worker left behind resumed instead of restarted.
 * Requires the HasKanvasAgentBehavior properties.
 */
trait RunsDurably
{
    /**
     * Whether this agent type keeps its run in Redis so a turn killed with the worker (OOM, timeout,
     * deploy) resumes from its last committed step instead of restarting and repeating a write. Off by
     * default; internal teammates opt in.
     */
    protected function durableRuns(): bool
    {
        return false;
    }

    protected function durableRunsActive(): bool
    {
        return $this->durableRuns()
            && $this->app !== null
            && filter_var($this->app->get(AgentRunConfigurationEnum::DURABLE_RUNS->value), FILTER_VALIDATE_BOOL);
    }

    /**
     * Redis is already the queue and cache store, and the v4 adapter needs the phpredis client: with
     * any other client the run stays in memory, as it does for every agent that does not opt in.
     */
    #[Override]
    protected function persistence(): PersistenceInterface
    {
        if (! $this->durableRunsActive()) {
            return new InMemoryPersistence();
        }

        $client = RedisFacade::connection()->client();

        if (! $client instanceof Redis) {
            Log::warning('Durable agent runs need the phpredis client; falling back to in-memory runs', [
                'client' => $client::class,
            ]);

            return new InMemoryPersistence();
        }

        return new RedisPersistence($client, 'kanvas:agent-run:');
    }

    /**
     * A plain start would sweep a dead run and replay its tools from the first; recovery asks the engine
     * to continue that exact run, by id, and the engine refuses while another worker's lease is fresh.
     * Only the same inbound message is recovered: a different message is a new turn, whatever was left
     * behind.
     */
    public function recoverInterruptedRun(Message $inbound): ?AgentState
    {
        if (! $this->durableRunsActive()) {
            return null;
        }

        $run = $this->inspect();

        if ($run === null || ! in_array($run->status, [WorkflowStatus::Failed, WorkflowStatus::Running], true)) {
            return null;
        }

        if (! self::sameTurn($run->startEvent, $inbound)) {
            return null;
        }

        $state = $this->run(ExecutionRequest::start($this->getStartEvent(), $run->runId, recoverFailed: true));

        return $state instanceof AgentState ? $state : null;
    }

    private static function sameTurn(Event $start, Message $inbound): bool
    {
        if (! $start instanceof AgentStartEvent || $start->messages === []) {
            return false;
        }

        $last = $start->messages[array_key_last($start->messages)];

        return $last instanceof Message && (string) $last->getContent() === (string) $inbound->getContent();
    }
}
