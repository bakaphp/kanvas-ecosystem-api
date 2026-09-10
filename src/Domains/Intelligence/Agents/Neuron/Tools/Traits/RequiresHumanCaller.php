<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Users\Models\Users;
use Throwable;

/**
 * For a self-service tool that acts AS the caller — files something in their name, or gates on their
 * ownership of a record. Returns the person, or the finished `denied()` payload to return verbatim.
 *
 *   $caller = $this->humanCallerOrDenial('file an expense', 'Say plainly that NOTHING was filed.');
 *   if (is_array($caller)) { return $caller; }
 *
 * `withContext()` carries `actingUser()`, which on a SystemUserAgent is the AGENT'S OWN user, so the
 * wired caller is routinely a bot. Two things follow. The host hands the identified person over
 * separately through `forConversationHuman()`, and that person wins when there is one. Failing that,
 * a turn acted by the agent itself is refused, because writing an obligation in the caller's name
 * would book it against a user that is not a person — a Due to Employees credit owed to an agent,
 * which nobody will ever claim and which quietly breaks the ledger's reconciliation against the
 * employee report.
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
    protected ?Users $conversationHuman = null;

    /**
     * The identified person in the conversation, which is NOT the tool's context user.
     *
     * MergesRegisteredTools fills this from `requestingHuman()`, the same way it feeds an
     * admin-guarded tool — because `withContext()` carries `actingUser()`, i.e. the agent's own
     * user, on every surface. Without it a self-service tool granted to an agent from the catalog
     * would see a bot as the caller and refuse a real person standing right there.
     */
    public function forConversationHuman(?Users $user): static
    {
        $this->conversationHuman = $user;

        return $this;
    }

    /**
     * The person this turn is for: the identified human when the host knew one, else whoever the
     * tool was wired with.
     */
    private function callerCandidate(): ?Users
    {
        return $this->conversationHuman ?? $this->contextUser();
    }

    /**
     * @param array<string, mixed> $payload merged into the refusal, for a tool whose family carries
     *        extra keys on a failed write (`created => false`, …)
     * @return Users|array<string, mixed>
     */
    protected function humanCallerOrDenial(string $action, string $guidance, array $payload = []): Users|array
    {
        $user = $this->callerCandidate();

        if ($user === null) {
            return $this->denied(
                "I cannot tell who you are on this surface, so I cannot {$action} in your name.",
                ['status' => 'no_user_context', ...$payload],
                guidance: $guidance,
            );
        }

        if ($this->actsAsTheAgent($user)) {
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
        return $this->callerCandidate() ?? $this->denied(
            "I cannot tell who you are on this surface, so I cannot look up your {$subject}.",
            ['status' => 'no_user_context'],
        );
    }

    /**
     * Whether THIS TURN is being acted by the agent rather than by a person.
     *
     * Deliberately not "does a row in `agents` point at this user". An agent's user is routinely a
     * real person's — one user backs 28 agents in production, and on a dev box it is usually the
     * developer's own login — so that question locks the actual human out of their own expenses.
     * The question that matters is narrower: was this tool wired with the identity of the agent
     * running the turn? That is true only on the actingUser() path, which is exactly the case the
     * guard exists for.
     *
     * With no acting agent in context there is nothing to compare against and no evidence of a bot,
     * so the caller is taken at face value — refusing on a maybe is how a person gets locked out.
     */
    private function actsAsTheAgent(Users $user): bool
    {
        $agent = $this->contextAgent();

        if ($agent !== null && (int) $agent->user_id === $user->getId()) {
            return true;
        }

        try {
            // The shared AI user is a purpose-made bot identity, never a person's login, so it is a
            // safe positive. A config pointing at a deleted user throws — that id cannot be the live
            // caller either way.
            return $this->company->getAiAgentUser()?->getId() === $user->getId();
        } catch (Throwable) {
            return false;
        }
    }
}
