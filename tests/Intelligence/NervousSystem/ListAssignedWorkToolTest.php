<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAssignedWorkTool;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use Kanvas\Users\Models\Users;
use Tests\Intelligence\NervousSystem\Concerns\BuildsProjectBoard;
use Tests\TestCase;

class ListAssignedWorkToolTest extends TestCase
{
    use BuildsProjectBoard;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow', 'ecosystem'];

    private function tool(): ListAssignedWorkTool
    {
        [$app, $company, $user] = $this->context();

        return new ListAssignedWorkTool()->withContext($app, $company, $user);
    }

    public function testListsPlansAssignedToAHumanAcrossProjects(): void
    {
        Bus::fake();

        $alpha = $this->makeProject('Alpha');
        $beta = $this->makeProject('Beta');
        $human = $this->humanMemberOf($alpha);

        $mine = $this->planUnderProject($alpha, 'Tags on variants', ['add tags field', 'wire the UI']);
        $mine->assigned_users_id = $human->getId();
        $mine->saveQuietly();

        $mineElsewhere = $this->planUnderProject($beta, 'Beta cleanup');
        $mineElsewhere->assigned_users_id = $human->getId();
        $mineElsewhere->saveQuietly();

        $notMine = $this->planUnderProject($alpha, 'Backend sync');

        $result = $this->tool()(users_id: $human->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame('user', $result['member']['type']);
        $this->assertSame($human->getId(), $result['member']['users_id']);

        $planIds = collect($result['plans'])->pluck('plan_id')->all();
        $this->assertContains($mine->getId(), $planIds);
        $this->assertContains($mineElsewhere->getId(), $planIds);
        $this->assertNotContains($notMine->getId(), $planIds);

        $tags = collect($result['plans'])->firstWhere('plan_id', $mine->getId());
        $this->assertSame($alpha->getId(), $tags['project_id']);
        $this->assertSame('member', $tags['owned_by']);
        $this->assertCount(2, $tags['tasks']);
        $this->assertSame(3, $result['total_tasks']);

        $scoped = $this->tool()(users_id: $human->getId(), project_id: $beta->getId());
        $this->assertSame([$mineElsewhere->getId()], collect($scoped['plans'])->pluck('plan_id')->all());
    }

    public function testListsAnAgentsOwnPlansAndTheTasksItHoldsOnOtherPlans(): void
    {
        Bus::fake();

        $project = $this->makeProject('Agent work');
        $worker = $this->makeAgent();

        $owned = $this->planUnderProject($project, 'Owned plan', ['a', 'b']);
        $owned->agent_id = $worker->getId();
        $owned->saveQuietly();

        $shared = $this->planUnderProject($project, 'Shared plan', ['theirs', 'mine']);
        $heldTask = $shared->tasks->firstWhere('title', 'mine');
        $heldTask->agent_id = $worker->getId();
        $heldTask->saveQuietly();

        $result = $this->tool()(agent_id: $worker->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame('agent', $result['member']['type']);

        $byPlan = collect($result['plans'])->keyBy('plan_id');
        $this->assertCount(2, $byPlan[$owned->getId()]['tasks']);
        $this->assertSame('member', $byPlan[$owned->getId()]['owned_by']);

        $this->assertStringContainsString('someone else', $byPlan[$shared->getId()]['owned_by']);
        $this->assertSame([$heldTask->getId()], collect($byPlan[$shared->getId()]['tasks'])->pluck('task_id')->all());
    }

    public function testOpenWorkOnlyUnlessIncludeClosed(): void
    {
        Bus::fake();

        $project = $this->makeProject('Closed work');
        $human = $this->humanMemberOf($project);

        $open = $this->planUnderProject($project, 'Still open', ['todo', 'stuck', 'done already']);
        $open->assigned_users_id = $human->getId();
        $open->saveQuietly();

        $finished = $open->tasks->firstWhere('title', 'done already');
        $finished->status = TaskStatusEnum::DONE->value;
        $finished->saveQuietly();

        $stuck = $open->tasks->firstWhere('title', 'stuck');
        $stuck->status = TaskStatusEnum::BLOCKED->value;
        $stuck->saveQuietly();

        $done = $this->planUnderProject(
            $project,
            'Shipped',
            ['x'],
            PlanStatusEnum::DONE,
        );
        $done->assigned_users_id = $human->getId();
        $done->saveQuietly();

        $openOnly = $this->tool()(users_id: $human->getId());
        $this->assertSame([$open->getId()], collect($openOnly['plans'])->pluck('plan_id')->all());
        $this->assertEqualsCanonicalizing(
            ['todo', 'stuck'],
            collect($openOnly['plans'][0]['tasks'])->pluck('title')->all(),
            'a blocked task is still open work to the PM',
        );

        $all = $this->tool()(users_id: $human->getId(), include_closed: true);
        $planIds = collect($all['plans'])->pluck('plan_id')->all();
        $this->assertContains($done->getId(), $planIds);
        $this->assertCount(3, collect($all['plans'])->firstWhere('plan_id', $open->getId())['tasks']);
    }

    public function testTasksWaitingOnThePersonAreReportedSeparately(): void
    {
        Bus::fake();

        $project = $this->makeProject('Waiting');
        $human = $this->humanMemberOf($project);

        $plan = $this->planUnderProject($project, 'Needs an answer', ['ask Audry']);
        $task = $plan->tasks->first();
        $task->status = TaskStatusEnum::WAITING->value;
        $task->waiting_on_users_id = $human->getId();
        $task->saveQuietly();

        $result = $this->tool()(users_id: $human->getId());

        $this->assertSame([], $result['plans']);
        $this->assertSame([$task->getId()], collect($result['tasks_waiting_on_them'])->pluck('task_id')->all());
        $this->assertSame($plan->getId(), $result['tasks_waiting_on_them'][0]['plan_id']);
    }

    public function testNothingAssignedSaysSoAndTellsTheModelNotToRetry(): void
    {
        Bus::fake();

        $human = $this->humanMemberOf($this->makeProject('Idle'));

        $result = $this->tool()(users_id: $human->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['total_plans']);
        $this->assertStringContainsString('Nothing is assigned', $result['note']);
        $this->assertStringContainsString('do not retry', $result['note']);
    }

    public function testRejectsBothOrNeitherMemberAndUnknownIds(): void
    {
        [, , $user] = $this->context();
        $tool = $this->tool();

        $neither = $tool();
        $this->assertSame('error', $neither['status']);
        $this->assertStringContainsString('exactly one', $neither['message']);

        $both = $tool(users_id: $user->getId(), agent_id: 1);
        $this->assertSame('error', $both['status']);

        $stranger = Users::factory()->create();
        $unknownUser = $tool(users_id: $stranger->getId());
        $this->assertSame('error', $unknownUser['status']);
        $this->assertStringContainsString('not a member of this company', $unknownUser['message']);

        $unknownAgent = $tool(agent_id: 999999999);
        $this->assertSame('error', $unknownAgent['status']);
        $this->assertStringContainsString('was not found', $unknownAgent['message']);
    }

    public function testContextBundleExposesThePlanOwner(): void
    {
        Bus::fake();

        $project = $this->makeProject('Bundle');
        $human = $this->humanMemberOf($project);

        $plan = $this->planUnderProject($project, 'Owned by a person');
        $plan->assigned_users_id = $human->getId();
        $plan->saveQuietly();

        $bundle = new ProjectContextService()->buildContextBundle($project->refresh(), historyLimit: 1)->toArray();
        $row = collect($bundle['plans'])->firstWhere('plan_id', $plan->getId());

        $this->assertSame($human->getId(), $row['assigned_users_id']);
        $this->assertNull($row['agent_id']);
    }
}
