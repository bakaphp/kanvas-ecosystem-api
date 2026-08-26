<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProjectForTool;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets the PM resolve a person or agent NAMED in the content (a transcript saying "Roberlina works on
 * Tally B2", "@Maria should approve this") into an actual project member — search the company's agents
 * AND users by name, add the match to the project, and return whether it can execute board work. If
 * nothing matches, the PM is told so it can create the work UNASSIGNED and escalate to a human, rather
 * than assign to the wrong person. Agents are preferred over humans (they can execute).
 */
#[AgentTool(name: 'Find And Add Member', category: 'nervous_system')]
class FindAndAddNervousSystemMemberTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesProjectForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_and_add_nervous_system_member',
            description: 'Find a person or agent by NAME in this company and add them to the project as a '
                . 'member — use when the content names who should own/do something but they are not yet a '
                . 'member. Returns whether it was found and whether that member can execute board work. If '
                . 'not found, create the work unassigned and escalate to a human instead.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'project_id',
                type: PropertyType::INTEGER,
                description: 'The project to add the member to.',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The name (or handle) of the person/agent to find, as it appears in the content.',
                required: true,
            ),
            new ToolProperty(
                name: 'role',
                type: PropertyType::STRING,
                description: 'Member role: owner | manager | contributor | reviewer | viewer (default contributor).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $project_id, string $name, ?string $role = null): array
    {
        $project = $this->resolveProjectOrError($project_id);

        if (is_array($project)) {
            return $project;
        }

        $memberRole = ProjectMemberRoleEnum::tryFrom((string) $role) ?? ProjectMemberRoleEnum::CONTRIBUTOR;

        // Agents first — they can actually execute the work.
        $agent = Agent::query()
            ->where('name', 'like', '%' . trim($name) . '%')
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($agent !== null) {
            return $this->add($project, $memberRole, agent: $agent);
        }

        $user = $this->findUserByName(trim($name));
        if ($user !== null) {
            return $this->add($project, $memberRole, user: $user);
        }

        return [
            'found' => false,
            'name' => $name,
            'message' => "No agent or user matching \"{$name}\" was found. Create the work unassigned and "
                . '@mention the project owner so a human can assign it.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function add(
        Project $project,
        ProjectMemberRoleEnum $role,
        ?Agent $agent = null,
        ?Users $user = null,
    ): array {
        try {
            $member = new AddProjectMemberAction(
                project: $project,
                role: $role,
                user: $user,
                agent: $agent,
            )->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'found' => true,
            'member_id' => $member->getId(),
            'member_type' => $member->member_type,
            'agent_id' => $member->agent_id,
            'name' => $agent?->name ?? $member->user?->displayname,
            'can_execute' => (bool) $agent?->canExecuteBoardWork(),
        ];
    }

    /**
     * Search ONLY users that belong to this project's group — the app + company — never a global
     * users search, which would cross tenants and could add someone from another company. Scoped via
     * the users_associated_apps membership row (apps_id + companies_id); matches app displayname or
     * first/last name.
     */
    private function findUserByName(string $name): ?Users
    {
        $like = '%' . $name . '%';

        $usersId = UsersAssociatedApps::query()
            ->join('users', 'users.id', '=', 'users_associated_apps.users_id')
            ->where('users_associated_apps.apps_id', $this->app->getId())
            ->where('users_associated_apps.companies_id', $this->company->getId())
            ->where(function ($query) use ($like): void {
                $query->where('users_associated_apps.displayname', 'like', $like)
                    ->orWhere('users.firstname', 'like', $like)
                    ->orWhere('users.lastname', 'like', $like);
            })
            ->value('users_associated_apps.users_id');

        return $usersId !== null ? Users::getById((int) $usersId) : null;
    }
}
