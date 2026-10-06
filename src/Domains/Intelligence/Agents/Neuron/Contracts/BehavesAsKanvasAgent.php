<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Message;

/**
 * Every entry point binds a thread (AgentInterface::setThreadId) before running: the session uuid in
 * userChat, the entity uuid on a channel, a suffixed parent thread for a sub-agent.
 */
interface BehavesAsKanvasAgent extends AgentInterface, ProvidesToolDependencies
{
    public function setConfiguration(Agent $agent, ?Model $entity = null, ?Users $user = null): void;

    public function setSession(?Session $session): void;

    public function setCurrentLead(?Lead $lead): void;

    /** @param list<string> $media */
    public function setTurnMedia(array $media): void;

    /** Flag the current turn's user message private (persisted is_public=0) so the UI hides it. */
    public function setPrivateUserTurn(bool $private): void;

    /** Whether the reply lands on a surface that renders `kanvas-artifact` blocks (the admin userChat). */
    public function setRendersArtifacts(bool $renders): void;

    public function persistsTurnsToConversationStore(): bool;

    public function resolvedModelName(): string;

    /**
     * The turn a dead worker left behind for this thread and this message, continued from its last
     * committed step: tools that already ran are not run again. Null when there is nothing to recover.
     */
    public function recoverInterruptedRun(Message $inbound): ?AgentState;

    /** Drop a cancelled turn from the model's window and release the thread for the next message. */
    public function discardTurn(Message $message): void;
}
