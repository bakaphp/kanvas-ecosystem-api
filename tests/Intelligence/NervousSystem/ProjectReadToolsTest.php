<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\GetProjectAnalyticsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListProjectsTool;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProjectReadToolsTest extends TestCase
{
    use DatabaseTransactions;

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

    private function makeProject(string $title, ProjectStatusEnum $status, ?Carbon $deadline, int $completionPct): Project
    {
        [$app, $company, $user] = $this->context();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);

        $project = new CreateProjectAction(
            ProjectData::from($app, $user, $company, [
                'title' => $title,
                'agent_id' => $agent->id,
                'status' => $status->value,
                'deadline_at' => $deadline?->toDateTimeString(),
            ]),
        )->execute();

        // completion_pct isn't a create-time field; set it directly for the at-risk scenarios.
        $project->completion_pct = $completionPct;
        $project->saveQuietly();

        return $project;
    }

    public function testListProjectsFlagsOverdueAndBlockedAsAtRisk(): void
    {
        Queue::fake();
        $now = Carbon::now();

        $overdue = $this->makeProject('Overdue build', ProjectStatusEnum::ACTIVE, $now->copy()->subDays(3), 40);
        $blocked = $this->makeProject('Blocked build', ProjectStatusEnum::BLOCKED, null, 20);
        $healthy = $this->makeProject('On track', ProjectStatusEnum::ACTIVE, $now->copy()->addDays(30), 20);

        [$app, $company, $user] = $this->context();
        $result = new ListProjectsTool()->withContext($app, $company, $user)->__invoke(limit: 100);

        $this->assertSame('success', $result['status']);

        $byId = collect($result['projects'])->keyBy('id');

        $this->assertTrue($byId[$overdue->id]['at_risk'], 'overdue project is at risk');
        $this->assertTrue($byId[$overdue->id]['overdue']);
        $this->assertTrue($byId[$blocked->id]['at_risk'], 'blocked project is at risk');
        $this->assertFalse($byId[$healthy->id]['at_risk'], 'future-deadline project is not at risk');
    }

    public function testListProjectsAtRiskOnlyFiltersToRiskyProjects(): void
    {
        Queue::fake();
        $now = Carbon::now();

        $overdue = $this->makeProject('Slipping', ProjectStatusEnum::ACTIVE, $now->copy()->subDay(), 10);
        $healthy = $this->makeProject('Fine', ProjectStatusEnum::ACTIVE, $now->copy()->addDays(20), 50);

        [$app, $company, $user] = $this->context();
        $result = new ListProjectsTool()->withContext($app, $company, $user)->__invoke(at_risk_only: true, limit: 100);

        $ids = collect($result['projects'])->pluck('id')->all();

        $this->assertContains($overdue->id, $ids);
        $this->assertNotContains($healthy->id, $ids);
    }

    public function testListProjectsRejectsUnknownStatus(): void
    {
        [$app, $company, $user] = $this->context();
        $result = new ListProjectsTool()->withContext($app, $company, $user)->__invoke(status: 'not_a_status');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Unknown project status', $result['message']);
    }

    public function testProjectAnalyticsAggregatesPortfolioHealth(): void
    {
        Queue::fake();
        $now = Carbon::now();

        $this->makeProject('Overdue A', ProjectStatusEnum::ACTIVE, $now->copy()->subDays(2), 30);
        $this->makeProject('Blocked B', ProjectStatusEnum::BLOCKED, null, 10);
        $this->makeProject('Due soon C', ProjectStatusEnum::ACTIVE, $now->copy()->addDays(3), 60);
        $this->makeProject('Done D', ProjectStatusEnum::DONE, null, 100);

        [$app, $company, $user] = $this->context();
        $result = new GetProjectAnalyticsTool()->withContext($app, $company, $user)->__invoke(due_soon_days: 7);

        $this->assertSame('success', $result['status']);
        // At least the three open ones we created (A, B, C); Done D is excluded from open counts.
        $this->assertGreaterThanOrEqual(3, $result['open_projects']);
        $this->assertGreaterThanOrEqual(1, $result['overdue']);
        $this->assertGreaterThanOrEqual(1, $result['blocked']);
        $this->assertGreaterThanOrEqual(1, $result['due_soon']);
        $this->assertGreaterThanOrEqual(2, $result['at_risk']);
        $this->assertGreaterThanOrEqual(1, $result['by_status']['done'] ?? 0);
    }
}
