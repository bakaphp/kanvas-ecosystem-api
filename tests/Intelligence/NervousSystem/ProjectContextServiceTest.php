<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectContextServiceTest extends TestCase
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

    public function testBuildContextBundleAssemblesTheStory(): void
    {
        [$app, $company, $user] = $this->context();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $project = new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Launch', 'objective' => 'Ship the new site', 'agent_id' => $agent->id],
            ),
        )->execute();

        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::MANAGER,
            user: $user,
        )->execute();

        // A plan under the project so the bundle's open-work section is exercised.
        $plan = new Plan();
        $plan->apps_id = $app->getId();
        $plan->companies_id = $company->getId();
        $plan->users_id = $user->getId();
        $plan->project_id = $project->id;
        $plan->plan_type = 'project_work';
        $plan->title = 'Design phase';
        $plan->status = 'active';
        $plan->saveOrFail();

        $bundle = new ProjectContextService()->buildContextBundle($project);

        $this->assertSame('Ship the new site', $bundle->project['objective']);
        $this->assertSame('Launch', $bundle->project['title']);

        $this->assertNotEmpty($bundle->members);
        $this->assertSame('manager', $bundle->members[0]['role']);
        $this->assertSame('user', $bundle->members[0]['type']);

        // The PM needs a resolvable @handle to notify a human — present, and either null or @-prefixed.
        $this->assertArrayHasKey('handle', $bundle->members[0]);
        $handle = $bundle->members[0]['handle'];
        $this->assertTrue($handle === null || str_starts_with((string) $handle, '@'));

        $planTitles = array_column($bundle->plans, 'title');
        $this->assertContains('Design phase', $planTitles);

        // The PM needs ids to act on existing work.
        $this->assertArrayHasKey('plan_id', $bundle->plans[0]);
        $this->assertSame((int) $plan->id, (int) $bundle->plans[0]['plan_id']);

        $eventTypes = array_column($bundle->recentEvents, 'event_type');
        $this->assertContains('project.created', $eventTypes);

        $this->assertIsArray($bundle->recentMessages);
        $this->assertArrayHasKey('project', $bundle->toArray());
    }
}
