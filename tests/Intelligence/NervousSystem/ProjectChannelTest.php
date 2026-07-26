<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectChannelTest extends TestCase
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

    private function makeProject(Apps $app, Companies $company, Users $user): Project
    {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Channel test project', 'agent_id' => $agent->id],
            ),
        )->execute();
    }

    public function testCreateProjectBindsDefaultChannel(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $project->refresh();

        $this->assertNotNull($project->default_channel_id, 'project should have a bound default channel');

        $channel = $project->defaultChannel;
        $this->assertNotNull($channel);
        $this->assertSame(Project::class, $channel->entity_namespace);
        $this->assertSame((string) $project->id, (string) $channel->entity_id);

        $this->assertGreaterThanOrEqual(1, $project->channels()->count());
    }

    public function testDefaultChannelExposedViaGraphQL(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $this->graphQL('
            query ($id: Mixed) {
                nervousSystemProjects(where: { column: ID, operator: EQ, value: $id }) {
                    data { id defaultChannel { id } channels { id } }
                }
            }
        ', ['id' => $project->id])
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['nervousSystemProjects' => ['data' => [['defaultChannel' => ['id'], 'channels' => [['id']]]]]]]);
    }
}
