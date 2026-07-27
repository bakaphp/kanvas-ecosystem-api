<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class PlanProjectLinkTest extends TestCase
{
    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    private function makeProject(): Project
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        return new CreateProjectAction(
            ProjectData::from($app, $user, $company, ['title' => 'Board', 'agent_id' => $agent->id]),
        )->execute();
    }

    public function testCreatePlanWithProjectIdLinksItToTheProject(): void
    {
        $project = $this->makeProject();

        $response = $this->graphQL('
            mutation ($input: CreateNervousSystemPlanInput!) {
                createNervousSystemPlan(input: $input) { id }
            }
        ', ['input' => [
            'title' => 'New board plan',
            'plan_type' => 'project_work',
            'project_id' => (int) $project->id,
        ]])->assertSuccessful();

        $planId = (int) $response->json('data.createNervousSystemPlan.id');

        $this->assertSame(
            (int) $project->id,
            (int) $project->plans()->where('id', $planId)->value('project_id'),
        );
    }

    public function testFilterPlansByProjectId(): void
    {
        $project = $this->makeProject();

        $linkedId = (int) $this->graphQL('
            mutation ($input: CreateNervousSystemPlanInput!) {
                createNervousSystemPlan(input: $input) { id }
            }
        ', ['input' => ['title' => 'Linked', 'plan_type' => 'project_work', 'project_id' => (int) $project->id]])
            ->json('data.createNervousSystemPlan.id');

        // An unlinked plan that must NOT show up under the project filter.
        $this->graphQL('
            mutation ($input: CreateNervousSystemPlanInput!) {
                createNervousSystemPlan(input: $input) { id }
            }
        ', ['input' => ['title' => 'Unlinked', 'plan_type' => 'project_work']])->assertSuccessful();

        $ids = $this->graphQL('
            query ($pid: Mixed!) {
                nervousSystemPlans(where: { column: PROJECT_ID, operator: EQ, value: $pid }) {
                    data { id }
                }
            }
        ', ['pid' => (int) $project->id])
            ->assertSuccessful()
            ->json('data.nervousSystemPlans.data.*.id');

        $ids = array_map('intval', $ids);
        $this->assertContains($linkedId, $ids);
        $this->assertCount(1, $ids);
    }
}
