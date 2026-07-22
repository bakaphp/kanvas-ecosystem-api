<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectMemberCrudTest extends TestCase
{
    /**
     * @return array{0: Apps, 1: Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany(), $user];
    }

    private function makeAgent(Apps $app, Companies $company, Users $user): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
    }

    private function makeProject(Apps $app, Companies $company, Users $user): Project
    {
        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Members test', 'agent_id' => $this->makeAgent($app, $company, $user)->id],
            ),
        )->execute();
    }

    public function testAddUserMemberViaGraphQL(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $this->graphQL('
            mutation ($project_id: ID!, $input: AddNervousSystemProjectMemberInput!) {
                addNervousSystemProjectMember(project_id: $project_id, input: $input) {
                    id member_type role user { id } agent { id }
                }
            }
        ', [
            'project_id' => $project->id,
            'input' => ['users_id' => $user->getId(), 'role' => 'manager'],
        ])
            ->assertSuccessful()
            ->assertJson(['data' => ['addNervousSystemProjectMember' => [
                'member_type' => 'user',
                'role' => 'manager',
                'user' => ['id' => (string) $user->getId()],
                'agent' => null,
            ]]]);
    }

    public function testAddAgentMemberDerivesUserFromAgent(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $agent = $this->makeAgent($app, $company, $user);

        $response = $this->graphQL('
            mutation ($project_id: ID!, $input: AddNervousSystemProjectMemberInput!) {
                addNervousSystemProjectMember(project_id: $project_id, input: $input) {
                    id member_type user { id } agent { id }
                }
            }
        ', [
            'project_id' => $project->id,
            'input' => ['agent_id' => (int) $agent->id, 'role' => 'contributor'],
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.addNervousSystemProjectMember');

        $this->assertSame('agent', $data['member_type']);
        $this->assertSame((string) $agent->id, $data['agent']['id']);
        $this->assertSame((string) $agent->user_id, $data['user']['id']);
    }

    public function testAddMemberRequiresUserOrAgent(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $this->graphQL('
            mutation ($project_id: ID!, $input: AddNervousSystemProjectMemberInput!) {
                addNervousSystemProjectMember(project_id: $project_id, input: $input) { id }
            }
        ', ['project_id' => $project->id, 'input' => ['role' => 'contributor']])
            ->assertGraphQLErrorMessage('Provide a users_id or an agent_id.');
    }

    public function testUpdateMemberRole(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $id = $this->graphQL('
            mutation ($project_id: ID!, $input: AddNervousSystemProjectMemberInput!) {
                addNervousSystemProjectMember(project_id: $project_id, input: $input) { id }
            }
        ', ['project_id' => $project->id, 'input' => ['users_id' => $user->getId()]])
            ->json('data.addNervousSystemProjectMember.id');

        $this->graphQL('
            mutation ($id: ID!, $role: String!) {
                updateNervousSystemProjectMemberRole(id: $id, role: $role) { id role }
            }
        ', ['id' => $id, 'role' => 'reviewer'])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateNervousSystemProjectMemberRole' => ['role' => 'reviewer']]]);
    }

    public function testRemoveMemberSoftDeletes(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $id = $this->graphQL('
            mutation ($project_id: ID!, $input: AddNervousSystemProjectMemberInput!) {
                addNervousSystemProjectMember(project_id: $project_id, input: $input) { id }
            }
        ', ['project_id' => $project->id, 'input' => ['users_id' => $user->getId()]])
            ->json('data.addNervousSystemProjectMember.id');

        $this->graphQL('
            mutation ($id: ID!) { removeNervousSystemProjectMember(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['removeNervousSystemProjectMember' => true]]);

        $this->assertSame(
            1,
            (int) ProjectMember::query()->withTrashed()->where('id', $id)->value('is_deleted'),
        );
    }

    public function testReAddingRestoresMember(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $member = new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $user,
        )->execute();
        $member->softDelete();

        $restored = new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::MANAGER,
            user: $user,
        )->execute();

        $this->assertSame((int) $member->id, (int) $restored->id);
        $this->assertFalse((bool) $restored->is_deleted);
        $this->assertSame('manager', $restored->role);
    }

    public function testProjectMembersRelation(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $user,
        )->execute();

        $this->graphQL('
            query ($id: Mixed) {
                nervousSystemProjects(where: { column: ID, operator: EQ, value: $id }) {
                    data { id members { id member_type user { id } } }
                }
            }
        ', ['id' => $project->id])
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['nervousSystemProjects' => ['data' => [['members' => [['id', 'member_type']]]]]]]);
    }
}
