<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
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

    public function testCreateProjectWithAgentAndWorkspace(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $project = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                [
                    'title' => 'Website Redesign',
                    'objective' => 'Ship the new marketing site',
                    'agent_id' => $agent->id,
                    'heartbeat_interval_minutes' => 10,
                ],
            ),
        )->execute();

        $this->assertSame('Website Redesign', $project->title);
        $this->assertSame('draft', $project->status);
        $this->assertSame(10, $project->heartbeat_interval_minutes);
        $this->assertSame((int) $agent->id, (int) $project->agent_id);
        $this->assertNotNull($project->workspace_id);

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Project::class,
                'source_entity_id' => $project->id,
                'event_type' => 'project.created',
            ],
            'intelligence',
        );
    }

    public function testCreateProjectRequiresAnAgent(): void
    {
        [$app, $company, $user] = $this->context();

        $this->expectException(ValidationException::class);

        new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'No agent'],
            ),
        )->execute();
    }

    public function testCreateProjectViaGraphQL(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $response = $this->graphQL('
            mutation ($input: CreateNervousSystemProjectInput!) {
                createNervousSystemProject(input: $input) {
                    id
                    title
                    status
                    completion_pct
                    heartbeat_interval_minutes
                    pmAgent { id }
                    workspace { id name }
                }
            }
        ', ['input' => ['title' => 'GraphQL Project', 'agent_id' => (int) $agent->id, 'heartbeat_interval_minutes' => 15]]);

        $response->assertSuccessful();
        $data = $response->json('data.createNervousSystemProject');

        $this->assertSame('GraphQL Project', $data['title']);
        $this->assertSame(15, $data['heartbeat_interval_minutes']);
        $this->assertSame((int) $agent->id, (int) $data['pmAgent']['id']);
        $this->assertNotNull($data['workspace']['id']);
    }

    public function testUpdateProjectViaGraphQL(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $project = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Old title', 'agent_id' => $agent->id],
            ),
        )->execute();

        $this->graphQL('
            mutation ($id: ID!, $input: UpdateNervousSystemProjectInput!) {
                updateNervousSystemProject(id: $id, input: $input) { id title status }
            }
        ', ['id' => $project->id, 'input' => ['title' => 'New title', 'status' => 'active']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateNervousSystemProject' => ['title' => 'New title', 'status' => 'active']]]);
    }

    public function testDeleteProjectViaGraphQL(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $project = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Doomed', 'agent_id' => $agent->id],
            ),
        )->execute();

        $this->graphQL('
            mutation ($id: ID!) { deleteNervousSystemProject(id: $id) }
        ', ['id' => $project->id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteNervousSystemProject' => true]]);

        $this->assertSame(
            1,
            (int) Project::query()->withTrashed()->where('id', $project->id)->value('is_deleted'),
        );
    }

    public function testReassignPmAgentViaGraphQL(): void
    {
        [$app, $company, $user] = $this->context();
        $original = $this->makeAgent($app, $company, $user);
        $replacement = $this->makeAgent($app, $company, $user);

        $project = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Reassign PM', 'agent_id' => $original->id],
            ),
        )->execute();

        $this->assertSame((int) $original->id, (int) $project->agent_id);

        $this->graphQL('
            mutation ($id: ID!, $input: UpdateNervousSystemProjectInput!) {
                updateNervousSystemProject(id: $id, input: $input) { id pmAgent { id } }
            }
        ', ['id' => $project->id, 'input' => ['agent_id' => (int) $replacement->id]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateNervousSystemProject' => [
                'pmAgent' => ['id' => (string) $replacement->id],
            ]]]);

        $this->assertSame(
            (int) $replacement->id,
            (int) Project::query()->where('id', $project->id)->value('agent_id'),
        );
    }

    public function testUpdateWithoutAgentIdKeepsCurrentPm(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $project = new CreateProjectAction(
            ProjectData::from($app, $user, $company, ['title' => 'Keep PM', 'agent_id' => $agent->id]),
        )->execute();

        $this->graphQL('
            mutation ($id: ID!, $input: UpdateNervousSystemProjectInput!) {
                updateNervousSystemProject(id: $id, input: $input) { id pmAgent { id } }
            }
        ', ['id' => $project->id, 'input' => ['title' => 'Renamed only']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateNervousSystemProject' => [
                'pmAgent' => ['id' => (string) $agent->id],
            ]]]);
    }

    public function testInvalidHeartbeatIntervalIsRejected(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $this->expectException(ValidationException::class);

        new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                [
                    'title' => 'Bad cadence',
                    'agent_id' => $agent->id,
                    'heartbeat_interval_minutes' => 7,
                ],
            ),
        )->execute();
    }

    public function testProjectTreeParentChildAndPathViaAsTree(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $parent = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Parent program', 'agent_id' => $agent->id],
            ),
        )->execute();

        $child = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                [
                    'title' => 'Child project',
                    'agent_id' => $agent->id,
                    'parent_project_id' => $parent->id,
                ],
            ),
        )->execute();

        // Trait-provided relations resolve off parent_project_id (overridden parent key).
        $this->assertSame((int) $parent->id, (int) $child->parent->id);
        $this->assertTrue($parent->children->pluck('id')->contains($child->id));

        // Materialized path is assigned by AsTree and encodes the ancestry.
        $this->assertNotNull($child->path);
        $this->assertTrue($parent->descendants()->pluck('id')->contains($child->id));
        $this->assertTrue($child->ancestors()->pluck('id')->contains($parent->id));
    }

    public function testChildrenRelationExcludesSoftDeleted(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $parent = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Parent', 'agent_id' => $agent->id],
            ),
        )->execute();

        $child = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Child', 'agent_id' => $agent->id, 'parent_project_id' => $parent->id],
            ),
        )->execute();

        $child->softDelete();

        $this->assertFalse($parent->children()->pluck('id')->contains($child->id));
    }

    public function testListProjects(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Listed project', 'agent_id' => $agent->id],
            ),
        )->execute();

        $this->graphQL('
            query { nervousSystemProjects(first: 10) { data { id title } } }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['nervousSystemProjects' => ['data' => [['id', 'title']]]]]);
    }

    /**
     * Agent carries a SoftDeletingScope on is_deleted, so a soft-deleted PM drops out of the
     * belongsTo and pmAgent resolves to null — a non-null schema field crashed the whole list
     * with InvariantViolation (Sentry KANVAS-ECOSYSTEM-5GS).
     */
    public function testListProjectsWithSoftDeletedPmAgent(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $project = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Orphaned PM project', 'agent_id' => $agent->id],
            ),
        )->execute();

        $agent->softDelete();

        $this->graphQL('
            query ($id: Mixed!) {
                nervousSystemProjects(where: { column: ID, operator: EQ, value: $id }) {
                    data { id title pmAgent { id name } }
                }
            }
        ', ['id' => $project->id])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.nervousSystemProjects.data.0.id', (string) $project->id)
            ->assertJsonPath('data.nervousSystemProjects.data.0.pmAgent', null);
    }
}
