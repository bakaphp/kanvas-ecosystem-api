<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * For a self-service tool that acts AS the caller — files something in their name, or gates on their
 * ownership of a record. Returns the person, or the finished `denied()` payload to return verbatim.
 *
 *   $caller = $this->humanCallerOrDenial('file an expense', 'Say plainly that NOTHING was filed.');
 *   if (is_array($caller)) { return $caller; }
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
 * person to do it from their own chat. A read-only "my …" tool wants `knownCallerOrDenial()`
 * instead — reporting an agent's own empty position is useless, not harmful.
 *
 * Requires HasKanvasContext (`$this->app` / `$this->company` / `contextUser()`) and
 * ReportsToolOutcome (`denied()`) on the host.
 */
trait RequiresHumanCaller
{
    /**
     * @param array<string, mixed> $payload merged into the refusal, for a tool whose family carries
     *        extra keys on a failed write (`created => false`, …)
     * @return Users|array<string, mixed>
     */
    protected function humanCallerOrDenial(string $action, string $guidance, array $payload = []): Users|array
    {
        $user = $this->contextUser();

        if ($user === null) {
            return $this->denied(
                "I cannot tell who you are on this surface, so I cannot {$action} in your name.",
                ['status' => 'no_user_context', ...$payload],
                guidance: $guidance,
            );
        }

        if ($this->isAgentIdentity($user)) {
            return $this->denied(
                'This conversation is running as an agent rather than as a person, so there is '
                . "nobody to {$action} for. Ask the person to do it from their own chat with me.",
                ['status' => 'agent_caller', ...$payload],
                guidance: $guidance,
            );
        }

        return $user;
    }

    /**
     * The read-only variant: a tool reporting the caller's OWN records still has to know who they
     * are, but does not care whether that is a person — an agent asking after its own reimbursements
     * gets a correct, empty answer.
     *
     * @return Users|array<string, mixed>
     */
    protected function knownCallerOrDenial(string $subject): Users|array
    {
        return $this->contextUser() ?? $this->denied(
            "I cannot tell who you are on this surface, so I cannot look up your {$subject}.",
            ['status' => 'no_user_context'],
        );
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
