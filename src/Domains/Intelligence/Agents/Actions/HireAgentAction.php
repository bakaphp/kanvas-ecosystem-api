<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

/**
 * Create a working teammate agent: its own user, its own instructions, its own tools.
 *
 * **The dedicated user is the point, not a detail.** Agents that share a user share an identity, and
 * two things break at once: ledger memory accrues to whoever else holds that user, and any guard that
 * asks "did this agent produce this record?" answers yes for work the agent never did. The channel
 * wake guard compares a record's `users_id` against the agent's — point a new agent at the user that
 * owns the WhatsApp receiver and it skips every inbound message rather than just its own output,
 * silently, with no error anywhere.
 *
 * A true agent, never a sub-agent: a sub-agent is a private function of its parent, not a teammate
 * that can be assigned work and hold a conversation.
 */
class HireAgentAction
{
    /**
     * @param list<Tool> $tools
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $hiredBy,
        private readonly ?Agent $hiredByAgent,
        private readonly AgentType $agentType,
        private readonly string $name,
        private readonly string $role,
        private readonly string $instructions,
        private readonly array $tools = [],
        private readonly ?string $soul = null,
        private readonly ?string $outputFormat = null,
    ) {
    }

    public function execute(): Agent
    {
        $name = trim($this->name);

        if ($name === '') {
            throw new ValidationException('An agent needs a name.');
        }

        if (trim($this->instructions) === '') {
            throw new ValidationException(
                'An agent needs instructions — without them it has tools and no job.'
            );
        }

        $existing = Agent::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('is_deleted', 0)
            ->first();

        if ($existing !== null) {
            throw new ValidationException(sprintf(
                'This company already has an agent called "%s" (id %d). Retune that one instead of '
                . 'hiring a second with the same name.',
                $existing->name,
                $existing->getId()
            ));
        }

        // Resolved before anything is created. It is the step most likely to fail (roles are per-app
        // and scope-filtered), and failing after the user exists leaves an orphan whose email then
        // blocks every retry with "Email has already been taken".
        $role = $this->resolveRole();

        return new CreateAgentAction(new AgentData(
            app: $this->app,
            company: $this->company,
            user: $this->provisionUser($name, $role),
            agentType: $this->agentType,
            name: $name,
            role: trim($this->role) !== '' ? trim($this->role) : $name,
            is_active: true,
            soul: $this->soul,
            instructions: $this->instructions,
            outputFormat: $this->outputFormat,
            tools: $this->tools,
            // Lineage only — `isSubAgent` stays false, so no SUB_AGENT tool row is minted and the hire
            // is a teammate, not a callable function of its hirer. The link is what lets the hirer
            // retune it later: without a recorded relationship the correction loop only reaches agents
            // that happen to share a project.
            parentAgent: $this->hiredByAgent,
            createdBy: $this->hiredBy,
            isSubAgent: false,
        ))->execute();
    }

    /**
     * A dedicated user per agent, addressed per company so two tenants can both hire a "Newsroom"
     * without colliding on an email that has to stay globally unique.
     *
     * An existing address is REUSED rather than treated as an error. The name is derived, so the only
     * way to meet one is a previous attempt that created the user and then failed — and the agent it
     * was for does not exist, because a duplicate name is refused earlier. Failing here would make
     * every retry after any partial failure impossible without manual cleanup.
     */
    private function provisionUser(string $name, Role $role): Users
    {
        $email = sprintf(
            'agent-%s-%d@%s',
            Str::slug($name),
            $this->company->getId(),
            trim((string) config('mail.agent_identity_domain', 'agents.kanvas.local'), '@')
        );

        $user = $this->existingUser($email);

        if ($user === null) {
            try {
                $user = new RegisterUsersAction(RegisterInput::from([
                    'email' => $email,
                    'password' => bin2hex(random_bytes(16)),
                    'firstname' => $name,
                    'lastname' => 'Agent',
                ]))->execute();
            } catch (Throwable $e) {
                throw new ValidationException(
                    'Could not create the agent\'s own user account: ' . $e->getMessage()
                );
            }
        }

        $this->attachToCompany($user, $role);

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

    private function attachToCompany(Users $user, Role $role): void
    {
        $branch = $this->company->branch ?? $this->company->branches()->first();

        if ($branch === null) {
            throw new ValidationException(
                'This company has no branch, so the agent\'s user cannot be attached to it.'
            );
        }

        new AssignCompanyAction($user, $branch, $role, $this->app)->execute();
    }

    /**
     * A teammate should be able to act, not administer the tenant that hired it — but role names are
     * per-app, and `Agents` exists in some apps and not others. Falling through to `Users` keeps the
     * agent unprivileged where `Agents` was never created; failing loudly is the only other honest
     * option, because silently defaulting to Admin would hand every hire the run of the tenant.
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
            'This app has no "%s" or "%s" role for the agent\'s user to hold, and hiring will not fall '
            . 'back to an administrator role. Ask an admin to create one.',
            RolesEnums::AGENT->value,
            RolesEnums::USER->value
        ));
    }
}
