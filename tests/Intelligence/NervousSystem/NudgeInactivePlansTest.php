<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\NudgeInactivePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\StalePlanNudgeService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class NudgeInactivePlansTest extends TestCase
{
    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

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
                ['title' => 'Inactive-plan project', 'agent_id' => $agent->id],
            ),
        )->execute();
    }

    /**
     * A plan under the project whose last activity is backdated past the threshold. Its `created`
     * observer builds the Activities channel the nudge posts to.
     *
     * @param array<string, mixed> $attrs
     */
    private function stalePlan(Project $project, Apps $app, Companies $company, Users $user, array $attrs = []): Plan
    {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Silent work ' . fake()->unique()->word(),
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $plan->project_id = $project->id;
        foreach ($attrs as $key => $value) {
            $plan->{$key} = $value;
        }
        $plan->saveQuietly();

        // Backdate the activity clock. created_at is the floor lastActivityAt() uses (updated_at is
        // intentionally ignored — it bumps on non-communicative backend touches).
        DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('id', $plan->id)
            ->update(['created_at' => now()->subHours(48), 'updated_at' => now()->subHours(48)]);

        return $plan->refresh();
    }

    public function testServiceFindsStalePlanAndIgnoresFreshOne(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $stale = $this->stalePlan($project, $app, $company, $user);

        $fresh = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Fresh work',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();
        $fresh->project_id = $project->id;
        $fresh->saveQuietly();

        $ids = new StalePlanNudgeService()->stalePlans($app, 24)->pluck('id')->all();

        $this->assertContains((int) $stale->id, array_map('intval', $ids));
        $this->assertNotContains((int) $fresh->id, array_map('intval', $ids));
    }

    public function testHumanAssignedPlanIsPingedAndOwnerNotified(): void
    {
        Notification::fake();

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $assignee = Users::factory()->create();
        $plan = $this->stalePlan($project, $app, $company, $user, ['assigned_users_id' => $assignee->getId()]);

        $result = new NudgeInactivePlanAction($plan, 24)->execute();

        $this->assertSame(NudgeInactivePlanAction::RESULT_PINGED_HUMAN, $result);

        // A nudge comment landed on the plan's channel.
        $posted = Message::query()
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $plan->socialChannels()->first()?->getId()))
            ->latest('id')
            ->first();
        $this->assertNotNull($posted);
        $this->assertStringContainsString('no activity', (string) ($posted->message['content'] ?? ''));

        // The human PM (project owner) is notified — owner differs from the assignee here.
        Notification::assertSentTo(
            $user,
            PlanProgressNotification::class,
            fn (PlanProgressNotification $n): bool => ($n->getData()['metadata']['change_type'] ?? null) === 'stale',
        );

        $this->assertDatabaseHas('nervous_system_events', [
            'source_entity_type' => Plan::class,
            'source_entity_id' => $plan->id,
            'event_type' => 'plan.staleness.detected',
        ], 'intelligence');
    }

    public function testAgentAssignedPlanIsRewokenThenEscalated(): void
    {
        Bus::fake([WakeWorkerForPlanJob::class]);
        Notification::fake();

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $worker = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())
            ->create(['user_id' => Users::factory()->create()->getId(), 'is_active' => true]);

        $plan = $this->stalePlan($project, $app, $company, $user, ['agent_id' => $worker->getId()]);

        // First pass: the agent went silent → re-wake it (no human ping yet).
        $first = new NudgeInactivePlanAction($plan, 24)->execute();
        $this->assertSame(NudgeInactivePlanAction::RESULT_REWOKE_AGENT, $first);
        Bus::assertDispatched(
            WakeWorkerForPlanJob::class,
            fn (WakeWorkerForPlanJob $job): bool => (int) $job->plan->getId() === (int) $plan->getId(),
        );

        // Second pass (force past the once-per-window guard): the re-wake produced nothing, so escalate.
        $second = new NudgeInactivePlanAction($plan->refresh(), 24, force: true)->execute();
        $this->assertSame(NudgeInactivePlanAction::RESULT_ESCALATED_AGENT, $second);
    }

    public function testSecondNudgeWithinWindowIsSkipped(): void
    {
        Notification::fake();

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $assignee = Users::factory()->create();
        $plan = $this->stalePlan($project, $app, $company, $user, ['assigned_users_id' => $assignee->getId()]);

        $this->assertSame(NudgeInactivePlanAction::RESULT_PINGED_HUMAN, new NudgeInactivePlanAction($plan, 24)->execute());

        // Same window, not forced → the ledger guard skips it (no spam).
        $this->assertSame(
            NudgeInactivePlanAction::RESULT_SKIPPED,
            new NudgeInactivePlanAction($plan->refresh(), 24)->execute(),
        );
    }

    public function testCommandNudgesStaleProjectPlansWithConfigurableHours(): void
    {
        Notification::fake();

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $assignee = Users::factory()->create();
        $plan = $this->stalePlan($project, $app, $company, $user, ['assigned_users_id' => $assignee->getId()]);

        Artisan::call('kanvas:nervous-system:nudge-inactive-plans', [
            '--hours' => 24,
            '--project' => $project->id,
        ]);

        $this->assertDatabaseHas('nervous_system_events', [
            'source_entity_type' => Plan::class,
            'source_entity_id' => $plan->id,
            'event_type' => 'plan.staleness.detected',
        ], 'intelligence');

        Notification::assertSentTo($user, PlanProgressNotification::class);
    }
}
