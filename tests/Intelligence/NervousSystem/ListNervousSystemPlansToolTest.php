<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListNervousSystemPlansTool;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\Intelligence\NervousSystem\Concerns\BuildsProjectBoard;
use Tests\TestCase;

class ListNervousSystemPlansToolTest extends TestCase
{
    use BuildsProjectBoard;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow', 'ecosystem'];

    private function tool(): ListNervousSystemPlansTool
    {
        [$app, $company, $user] = $this->context();

        return new ListNervousSystemPlansTool()->withContext($app, $company, $user);
    }

    public function testListsOpenPlansAcrossProjectsWithOwnerAndTaskCounts(): void
    {
        Bus::fake();

        $alpha = $this->makeProject('Alpha');
        $beta = $this->makeProject('Beta');
        $human = $this->humanMemberOf($alpha);
        $worker = $this->makeAgent();

        $humanOwned = $this->planUnderProject($alpha, 'Human owned', ['a', 'b']);
        $humanOwned->assigned_users_id = $human->getId();
        $humanOwned->saveQuietly();

        $blockedTask = $humanOwned->tasks->firstWhere('title', 'b');
        $blockedTask->status = TaskStatusEnum::BLOCKED->value;
        $blockedTask->saveQuietly();

        $agentOwned = $this->planUnderProject($beta, 'Agent owned');
        $agentOwned->agent_id = $worker->getId();
        $agentOwned->saveQuietly();

        $orphan = $this->planUnderProject($beta, 'Nobody owns this');
        $shipped = $this->planUnderProject(
            $alpha,
            'Shipped',
            ['x'],
            PlanStatusEnum::DONE,
        );

        $result = $this->tool()(limit: 100);

        $this->assertSame('success', $result['status']);
        $byId = collect($result['plans'])->keyBy('plan_id');

        $this->assertArrayHasKey($humanOwned->getId(), $byId);
        $this->assertArrayHasKey($agentOwned->getId(), $byId);
        $this->assertArrayHasKey($orphan->getId(), $byId);
        $this->assertArrayNotHasKey($shipped->getId(), $byId, 'done plans are not open work');

        $this->assertSame('user', $byId[$humanOwned->getId()]['owner']['type']);
        $this->assertSame($human->getId(), $byId[$humanOwned->getId()]['owner']['users_id']);
        $this->assertSame(2, $byId[$humanOwned->getId()]['open_tasks'], 'a blocked task is still open work to the PM');
        $this->assertSame(1, $byId[$humanOwned->getId()]['blocked_tasks']);
        $this->assertSame('Alpha', $byId[$humanOwned->getId()]['project_title']);

        $this->assertSame('agent', $byId[$agentOwned->getId()]['owner']['type']);
        $this->assertSame($worker->getId(), $byId[$agentOwned->getId()]['owner']['agent_id']);

        $this->assertNull($byId[$orphan->getId()]['owner']);

        $scoped = $this->tool()(project_id: $beta->getId(), limit: 100);
        $this->assertEqualsCanonicalizing(
            [$agentOwned->getId(), $orphan->getId()],
            collect($scoped['plans'])->pluck('plan_id')->all(),
        );
    }

    public function testUnassignedOnlyReturnsPlansNobodyOwns(): void
    {
        Bus::fake();

        $project = $this->makeProject('Unassigned');
        $owned = $this->planUnderProject($project, 'Owned');
        $owned->agent_id = $this->makeAgent()->getId();
        $owned->saveQuietly();
        $orphan = $this->planUnderProject($project, 'Orphan');

        $result = $this->tool()(project_id: $project->getId(), unassigned_only: true);

        $this->assertSame([$orphan->getId()], collect($result['plans'])->pluck('plan_id')->all());
        $this->assertSame(1, $result['unassigned_count']);
    }

    public function testStatusFilterAndOverdueOnly(): void
    {
        Bus::fake();

        $project = $this->makeProject('Deadlines');
        $late = $this->planUnderProject($project, 'Late');
        $late->deadline_at = Carbon::now()->subDays(2);
        $late->saveQuietly();

        $onTime = $this->planUnderProject($project, 'On time');
        $onTime->deadline_at = Carbon::now()->addDays(10);
        $onTime->saveQuietly();

        $lateButDone = $this->planUnderProject(
            $project,
            'Late but done',
            ['x'],
            PlanStatusEnum::DONE,
        );
        $lateButDone->deadline_at = Carbon::now()->subDays(5);
        $lateButDone->saveQuietly();

        $tool = $this->tool();

        $overdue = $tool(project_id: $project->getId(), overdue_only: true);
        $this->assertSame([$late->getId()], collect($overdue['plans'])->pluck('plan_id')->all());
        $this->assertTrue($overdue['plans'][0]['overdue']);

        $done = $tool(project_id: $project->getId(), status: 'done');
        $this->assertSame([$lateButDone->getId()], collect($done['plans'])->pluck('plan_id')->all());
        $this->assertFalse($done['plans'][0]['overdue'], 'a finished plan is never overdue');

        $bad = $tool(status: 'not_a_status');
        $this->assertSame('error', $bad['status']);
        $this->assertStringContainsString('Unknown plan status', $bad['message']);
    }
}
