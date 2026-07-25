<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\Actions\PostProjectMessageAction;
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

        // The PM needs each member's users_id to assign a plan/task to a HUMAN — without it, it can
        // only ever assign to agents and mislabels the owner (the live bug this fixes).
        $this->assertArrayHasKey('users_id', $bundle->members[0]);
        $this->assertSame((int) $user->getId(), (int) $bundle->members[0]['users_id']);

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

    public function testContextExcludesScaffoldingPromptsAndCapsLength(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $project = new CreateProjectAction(
            ProjectData::from($app, $user, $company, ['title' => 'Ctx', 'agent_id' => $agent->id]),
        )->execute();

        // A scaffolded wake prompt — must NOT be fed back as context (that's the growth loop).
        new PostProjectMessageAction(
            project: $project,
            verb: 'ai-chat',
            content: '[NS:project reason=mention project_id=1] You are the PM ...',
            author: $user,
        )->execute();

        // A very long normal message — must be capped.
        new PostProjectMessageAction(
            project: $project,
            verb: 'note',
            content: str_repeat('x', 5000),
            author: $user,
        )->execute();

        $bundle = new ProjectContextService()->buildContextBundle($project);
        $contents = array_column($bundle->recentMessages, 'content');

        foreach ($contents as $content) {
            $this->assertFalse(str_starts_with((string) $content, '[NS:'), 'scaffolding leaked into context');
            $this->assertLessThanOrEqual(1210, strlen((string) $content), 'message content not capped');
        }
    }
}
