<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Actions\EnsureCompanyOrchestratorAgentAction;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Moves an agent off a login it shares onto a user of its own.
 *
 * An agent's own user is how the platform tells "the agent" from "a person": a turn run by that user is
 * treated as nobody asking. An agent running on a real person's login makes that person invisible to
 * it. Only what the agent creates from here on is stamped under the new user; earlier records stay.
 */
class EnsureAgentHasDedicatedUserAction
{
    public function __construct(private readonly Agent $agent)
    {
    }

    public function execute(): Users
    {
        $current = $this->agent->user;

        if ($current !== null && $this->sharedBecause() === null) {
            return $current;
        }

        $user = new ProvisionAgentUserAction(
            $this->agent->app,
            $this->agent->company,
            sprintf('%s %d', $this->agent->name, $this->agent->getId()),
        )->execute();

        $this->agent->user_id = $user->getId();
        $this->agent->saveOrFail();
        $this->agent->unsetRelation('user');

        return $user;
    }

    /**
     * Why the agent's user is not its own, or null when it is.
     */
    public function sharedBecause(): ?string
    {
        $user = $this->agent->user;

        if ($user === null) {
            return 'it has no user';
        }

        if ($user->getId() === $this->companyAiUserId()) {
            return 'it runs on the company AI user';
        }

        $alsoUsedBy = Agent::query()
            ->fromApp($this->agent->app)
            ->notDeleted()
            ->where('user_id', $user->getId())
            ->where('id', '!=', $this->agent->getId())
            ->exists();

        if ($alsoUsedBy) {
            return 'another agent runs on the same user';
        }

        // A person's login, administrator or not: an agent on it treats that person's turns as its own,
        // so they are never "someone asking". Only a user made for an agent is the agent's own.
        return $this->wasMadeForAnAgent($user) ? null : 'its user was not created for an agent';
    }

    private function wasMadeForAnAgent(Users $user): bool
    {
        $domain = strtolower((string) substr(strrchr((string) $user->email, '@') ?: '', 1));

        return in_array(
            $domain,
            [ProvisionAgentUserAction::identityDomain(), EnsureCompanyOrchestratorAgentAction::USER_DOMAIN],
            true,
        );
    }

    private function companyAiUserId(): ?int
    {
        try {
            return $this->agent->company?->getAiAgentUser()?->getId();
        } catch (Throwable) {
            return null;
        }
    }
}
