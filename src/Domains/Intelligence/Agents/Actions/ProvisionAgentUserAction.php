<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Support\Random;
use Baka\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

/**
 * An agent's dedicated user, addressed per company so two tenants can both have a "Sally" without
 * colliding on an email that has to stay globally unique.
 *
 * The role is resolved first: it is the step most likely to fail (roles are per-app and
 * scope-filtered), and failing after the user exists leaves an orphan whose email then blocks every
 * retry with "Email has already been taken".
 *
 * The branch is resolved before the user, not after: registration with no branch in hand mints a
 * company named after whoever is registering ("<agent>CP") and makes it their default, so an agent
 * that attaches to the company afterwards still belongs to a throwaway tenant of its own.
 *
 * An existing address is REUSED rather than treated as an error. The name is derived, so the only
 * way to meet one is a previous attempt that created the user and then failed before its agent was
 * created. Failing here would make every retry after any partial failure impossible without manual
 * cleanup.
 */
final class ProvisionAgentUserAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly string $name
    ) {
    }

    public function execute(): Users
    {
        $role = $this->resolveRole();
        $branch = $this->companyBranch();

        $email = sprintf(
            'agent-%s-%d@%s',
            Str::slug($this->name),
            $this->company->getId(),
            trim((string) config('mail.agent_identity_domain', 'agents.kanvas.local'), '@')
        );

        $user = $this->existingUser($email);

        if ($user === null) {
            try {
                $register = new CreateUserAction(
                    RegisterInput::fromArray(
                        [
                            'email' => $email,
                            'password' => bin2hex(random_bytes(16)),
                            'firstname' => $this->name,
                            'lastname' => 'Agent',
                            'displayname' => Random::platformUniqueDisplayName($this->name),
                        ],
                        $branch,
                        $this->app
                    ),
                    $this->app
                );
                // A synthetic mailbox nobody reads: the REGISTERED workflow would fire tenant
                // onboarding for an agent that never signs in.
                $register->disableWorkflow();

                $user = $register->execute();
            } catch (Throwable $e) {
                throw new ValidationException(
                    'Could not create the agent\'s own user account: ' . $e->getMessage()
                );
            }
        }

        new AssignCompanyAction(
            $user,
            $branch,
            $role,
            $this->app
        )->execute();

        return $user;
    }

    private function existingUser(string $email): ?Users
    {
        try {
            return UsersRepository::getByEmail($email);
        } catch (Throwable) {
            return null;
        }
    }

    private function companyBranch(): CompaniesBranches
    {
        $branch = $this->company->branch ?? $this->company->branches()->first();

        if ($branch === null) {
            throw new ValidationException(
                'This company has no branch, so the agent\'s user cannot be attached to it.'
            );
        }

        return $branch;
    }

    /**
     * An agent should be able to act, not administer the tenant — but role names are per-app, and
     * `Agents` exists in some apps and not others. Falling through to `Users` keeps the agent
     * unprivileged where `Agents` was never created; failing loudly is the only other honest
     * option, because silently defaulting to Admin would hand every agent the run of the tenant.
     *
     * The Bouncer scope is set explicitly first: roles are scope-filtered automatically, and this runs
     * inside a long-lived queue worker whose scope belongs to whatever job ran before it. Without
     * this the lookup misses and throws `No query results for model [Role]` from deep inside the
     * assignment, nowhere near the cause.
     */
    private function resolveRole(): Role
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->app));

        foreach ([RolesEnums::AGENT, RolesEnums::USER] as $candidate) {
            try {
                return RolesRepository::getByNameFromApp($candidate->value, $this->app);
            } catch (Throwable) {
                continue;
            }
        }

        throw new ValidationException(sprintf(
            'This app has no "%s" or "%s" role for the agent\'s user to hold, and will not fall '
            . 'back to an administrator role. Ask an admin to create one.',
            RolesEnums::AGENT->value,
            RolesEnums::USER->value
        ));
    }
}
