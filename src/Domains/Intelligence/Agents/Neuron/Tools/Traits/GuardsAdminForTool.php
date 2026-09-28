<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;

trait GuardsAdminForTool
{
    protected ?Users $requestingUser = null;

    public function forRequestingUser(?Users $user): static
    {
        $this->requestingUser = $user;

        return $this;
    }

    /**
     * Bouncer abilities that authorize this tool in place of blanket admin. Empty means admin-only.
     *
     * Without this a capability could only be given by making the holder an administrator, which is
     * the wrong trade for an AGENT: an autonomous PM needs to hire and to write automation, and
     * handing it the run of the tenant to get there grants it everything else too. A named ability
     * lets exactly the one capability be granted.
     *
     * @return list<string>
     */
    protected function requiredAbilities(): array
    {
        return [];
    }

    /**
     * Bouncer's Scopable trait auto-filters every ability query by the process-current scope, which
     * in a queue worker belongs to whatever job ran before this one. Without pinning it here the
     * lookup silently misses and a correctly-granted agent is refused.
     */
    private function holdsRequiredAbility(Users $actor): bool
    {
        $abilities = $this->requiredAbilities();

        if ($abilities === []) {
            return false;
        }

        $app = property_exists($this, 'app') && isset($this->app) ? $this->app : null;

        if ($app instanceof Apps) {
            Bouncer::scope()->to(RolesEnums::getScope($app));
        }

        foreach ($abilities as $ability) {
            if ($actor->can($ability)) {
                return true;
            }
        }

        // Only on the way to denying: Bouncer caches an actor's abilities, and a long-lived Octane or
        // queue process holds that cache across the grant. Without this an admin grants the ability,
        // the agent keeps refusing, and nothing anywhere says why. Refreshing here costs one read on
        // a path that was about to fail anyway, and never touches the hit path.
        Bouncer::refreshFor($actor);

        foreach ($abilities as $ability) {
            if ($actor->can($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{created: false, updated: false, message: string}|null Error payload when denied, null when allowed.
     */
    protected function requireAdminOrError(): ?array
    {
        $actor = $this->requestingUser ?? ($this->user ?? null);

        if ($actor instanceof Users && ($actor->isAdmin() || $this->holdsRequiredAbility($actor))) {
            return null;
        }

        // Both flags so create-style and update-style tools each read a falsey outcome.
        return [
            'created' => false,
            'updated' => false,
            'message' => 'Only an administrator can perform this action.',
        ];
    }

    /**
     * Same guard, but with no fallback to the tool's context user. An agent's own user is usually an
     * admin, so on the registry path (where the context user IS the agent) `requireAdminOrError()`
     * would authorize anyone talking to the agent. Tools whose blast radius is the whole company —
     * automation that then runs unattended — must know the human and deny when they don't.
     *
     * @return array{created: false, updated: false, message: string}|null Error payload when denied, null when allowed.
     */
    protected function requireRequestingAdminOrError(): ?array
    {
        if ($this->requestingUser instanceof Users
            && ($this->requestingUser->isAdmin() || $this->holdsRequiredAbility($this->requestingUser))
        ) {
            return null;
        }

        // Written as an instruction, not a log line: the model reads this and has to decide what to do
        // next. Left as a bare refusal it retries, or abandons the whole run over one blocked step.
        $carryOn = ' Do not retry and do not stop: if you are working a task, mark THAT task blocked '
            . 'with this reason, then carry on with everything else you can do.';

        return [
            'created' => false,
            'updated' => false,
            'message' => $this->requestingUser instanceof Users
                ? 'You do not have permission to perform this action — it needs a company administrator, '
                    . 'or an account explicitly granted it.' . $carryOn
                : 'This action needs a known company administrator, and this run has no identified user.'
                    . $carryOn,
        ];
    }
}
