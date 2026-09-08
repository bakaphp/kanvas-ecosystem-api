<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * For a self-service tool that acts AS the caller — files something in their name, or gates on their
 * ownership of a record. Returns the person, or a structured error the host wraps in `denied()`.
 *
 *   $caller = $this->humanCallerOrError('file an expense');
 *   if (is_array($caller)) { return $this->denied((string) $caller['message'], $caller); }
 *
 * The check that matters is the second one. A tool wired through `addToolContext()` gets
 * `actingUser()`, which on a SystemUserAgent is the AGENT'S OWN user, and `requestingHuman()` falls
 * back to the same thing on every surface except an @mention (only RespondToMentionJob sets
 * conversationHuman). So "the caller" is routinely a bot, and a tool that writes an obligation in
 * the caller's name would book it against a user that is not a person — a Due to Employees credit
 * owed to an agent, which nobody will ever claim and which quietly breaks the ledger's reconciliation
 * against the employee report.
 *
 * Refusing is the right failure: the model is told plainly that nothing happened and to ask the
 * person to do it from their own chat. Read-only "my …" tools do not need this — reporting an
 * agent's own empty position is useless, not harmful.
 *
 * Requires HasKanvasContext on the host for `$this->app` / `$this->company` / `contextUser()`.
 */
trait RequiresHumanCaller
{
    /**
     * @return Users|array{status: string, message: string}
     */
    protected function humanCallerOrError(string $action): Users|array
    {
        $user = $this->contextUser();

        if ($user === null) {
            return [
                'status' => 'no_user_context',
                'message' => "I cannot tell who you are on this surface, so I cannot {$action} in your name.",
            ];
        }

        if ($this->isAgentIdentity($user)) {
            return [
                'status' => 'agent_caller',
                'message' => 'This conversation is running as an agent rather than as a person, so there is '
                    . "nobody to {$action} for. Ask the person to do it from their own chat with me.",
            ];
        }

        return $user;
    }

    private function isAgentIdentity(Users $user): bool
    {
        if (Agent::fromUser($user->getId(), $this->app, $this->company) instanceof Agent) {
            return true;
        }

        try {
            // The shared AI user backs agents that were never given a dedicated one. A config
            // pointing at a deleted user throws — that id cannot be the live caller either way.
            return $this->company->getAiAgentUser()?->getId() === $user->getId();
        } catch (Throwable) {
            return false;
        }
    }
}
