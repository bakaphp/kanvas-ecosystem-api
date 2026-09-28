<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\MatchesNameTerms;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCompanyUserForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesProjectForTool;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
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
    use MatchesNameTerms;
    use ResolvesCompanyUserForTool;
    use ResolvesProjectForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_and_add_nervous_system_member',
            description: 'Find a person or agent by NAME in this company and add them to the project as a '
                . 'member — use when the content names who should own/do something but they are not yet a '
                . 'member. Pass the full name as written ("Roberlina Vega"); add email to disambiguate when '
                . 'several people share a name. Returns whether it was found and whether that member can '
                . 'execute board work. If not found, create the work unassigned and escalate to a human.',
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
            new ToolProperty(
                name: 'email',
                type: PropertyType::STRING,
                description: 'The person\'s email, when known — the only safe way to pick between people who '
                    . 'share a name. Only pass one you were actually given; never guess it from the name.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $project_id,
        string $name,
        ?string $role = null,
        ?string $email = null,
    ): array {
        $name = trim($name);
        $email = trim((string) $email);

        if ($name === '' && $email === '') {
            return ['found' => false, 'error' => 'Provide the name (or email) of the person to find.'];
        }

        $project = $this->resolveProjectOrError($project_id);

        if (is_array($project)) {
            return $project;
        }

        $memberRole = ProjectMemberRoleEnum::tryFrom((string) $role) ?? ProjectMemberRoleEnum::CONTRIBUTOR;

        // Agents first — they can actually execute the work.
        $agent = $this->findAgentByName($name);

        if ($agent !== null) {
            return $this->add($project, $memberRole, agent: $agent);
        }

        // Email wins when given: it is the one term that identifies a single person.
        $searched = $email !== '' ? $email : $name;
        $candidates = $this->resolveCompanyUsers($searched);

        if ($candidates->count() > 1) {
            return [
                'found' => false,
                'ambiguous' => true,
                'name' => $searched,
                'candidates' => $candidates->map(fn (Users $u): array => [
                    'name' => trim($u->firstname . ' ' . $u->lastname),
                    'email' => $u->email,
                ])->all(),
                'message' => "Several people match \"{$searched}\". Ask which one, then call this tool again "
                    . 'with their email.',
            ];
        }

        $user = $candidates->first();

        if ($user !== null) {
            return $this->add($project, $memberRole, user: $user);
        }

        return [
            'found' => false,
            'name' => $searched,
            'message' => "No agent or user matching \"{$searched}\" was found. Create the work unassigned and "
                . '@mention the project owner so a human can assign it.',
        ];
    }

    /**
     * Verbatim first: an agent whose name contains the whole string is the one that was meant. Per-term
     * matching is the fallback only — it resolves "Roberlina Vega" against "Roberlina A. Vega", but it
     * is loose enough to reach a near-namesake, so it must never outrank an exact hit.
     */
    private function findAgentByName(string $name): ?Agent
    {
        if ($name === '') {
            return null;
        }

        $agent = $this->companyAgents()->where('name', 'like', '%' . $name . '%')->first();

        if ($agent !== null) {
            return $agent;
        }

        return count($this->nameTerms($name)) > 1
            ? $this->scopeToNameMatch($this->companyAgents(), ['name'], $name)->first()
            : null;
    }

    private function companyAgents(): Builder
    {
        return Agent::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted();
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
}
