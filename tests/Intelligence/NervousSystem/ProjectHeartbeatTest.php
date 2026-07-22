<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectHeartbeatService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectHeartbeatTest extends TestCase
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
            ->create(['user_id' => $user->getId(), 'is_active' => true]);

        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Heartbeat project', 'agent_id' => $agent->id],
            ),
        )->execute();
    }

    private function planWithPendingTask(Project $project, Apps $app, Companies $company, Users $user): Plan
    {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Work',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: [new TaskData(plan: null, title: 'waiting task', sequence: 0)],
        )->execute();

        $plan->project_id = $project->id;
        $plan->saveQuietly();

        return $plan;
    }

    public function testNeedsAttentionForPendingWaitingWork(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $this->planWithPendingTask($project, $app, $company, $user);

        $this->assertTrue(new ProjectHeartbeatService()->needsAttention($project));
    }

    public function testNoAttentionForEmptyProject(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $this->assertFalse(new ProjectHeartbeatService()->needsAttention($project));
    }

    public function testNoAttentionWhenWorkIsInFlight(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planWithPendingTask($project, $app, $company, $user);

        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();
        $task->status = TaskStatusEnum::IN_PROGRESS->value;
        $task->started_at = now();
        $task->saveQuietly();

        $this->assertFalse(new ProjectHeartbeatService()->needsAttention($project));
    }

    public function testCommandWakesDueProjectWithWaitingWork(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $this->planWithPendingTask($project, $app, $company, $user);

        Bus::fake([WakeAgentForProjectJob::class]);

        Artisan::call('kanvas:nervous-system:project-heartbeat');

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool =>
                $job->project->id === $project->id
                && $job->reason === WakeAgentForProjectJob::REASON_HEARTBEAT,
        );

        // Cadence advanced (anti-thrash).
        $this->assertNotNull($project->refresh()->next_heartbeat_at);

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Project::class,
                'source_entity_id' => $project->id,
                'event_type' => 'project.heartbeat.tick',
            ],
            'intelligence',
        );
    }

    public function testCommandSkipsProjectNotYetDue(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $this->planWithPendingTask($project, $app, $company, $user);

        // Not due for another hour, even though it has waiting work.
        $project->next_heartbeat_at = now()->addHour();
        $project->saveQuietly();

        Bus::fake([WakeAgentForProjectJob::class]);

        Artisan::call('kanvas:nervous-system:project-heartbeat');

        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }
}
