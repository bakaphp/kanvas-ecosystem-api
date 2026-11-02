<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;

/**
 * A project with plans, tasks and members under the test user's own app and company, for tests that
 * read the board rather than build it.
 */
trait BuildsProjectBoard
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

    private function makeAgent(): Agent
    {
        [$app, $company, $user] = $this->context();

        $type = AgentType::factory()->create([
            'apps_id' => $app->getId(),
            'handler' => ProjectManagerAgent::class,
        ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'agent_type_id' => $type->id, 'is_active' => true]);
    }

    private function makeProject(string $title): Project
    {
        [$app, $company, $user] = $this->context();

        return new CreateProjectAction(
            ProjectData::from($app, $user, $company, ['title' => $title, 'agent_id' => $this->makeAgent()->id]),
        )->execute();
    }

    /**
     * @param array<int, string> $taskTitles
     */
    private function planUnderProject(
        Project $project,
        string $title,
        array $taskTitles = ['task one'],
        PlanStatusEnum $status = PlanStatusEnum::ACTIVE,
    ): Plan {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: $title,
                planType: 'project_work',
                user: $user,
                status: $status,
            ),
            tasks: array_map(
                static fn (string $taskTitle, int $i): TaskData => new TaskData(plan: null, title: $taskTitle, sequence: $i),
                $taskTitles,
                array_keys($taskTitles),
            ),
        )->execute();

        $plan->project_id = $project->id;
        $plan->saveQuietly();

        return $plan;
    }

    private function humanMemberOf(Project $project): Users
    {
        [, $company] = $this->context();

        $human = Users::factory()->create();
        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $human,
        )->execute();

        // Tools resolve a human through company membership, which the member action does not create.
        $company->associateUser($human, true, $company->defaultBranch);

        return $human;
    }
}
