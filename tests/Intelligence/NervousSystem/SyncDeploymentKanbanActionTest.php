<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Actions\Kanban\SyncDeploymentKanbanAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Mockery;
use Tests\TestCase;

/**
 * Exercises the full ingest path (provider board → Kanvas Plans/Tasks) with a canned board, so no
 * SSH/runtime is touched. Asserts the root→Plan / child→Task tree, status mapping, link custom
 * fields, idempotent re-run, and that a runtime status change flips the Kanvas task.
 */
final class SyncDeploymentKanbanActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testIngestBuildsPlanTreeIsIdempotentAndReflectsStatusChanges(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'kanban-researcher']);

        $deployment = $this->fakeDeployment($agent, $app, $company);

        $action = new CannedSyncDeploymentKanbanAction($deployment);
        $action->board = $this->board(childAStatus: 'running');

        $result = $action->execute();

        $this->assertSame(1, $result['plans']);
        $this->assertSame(2, $result['tasks']);

        $plan = Plan::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('agent_id', $agent->getId())
            ->where('plan_type', 'hermes_kanban')
            ->first();

        $this->assertNotNull($plan);
        $this->assertSame('active', $plan->status); // ready → active
        $this->assertSame('t_root', $plan->get(KanbanCustomFieldEnum::TASK_ID->value));

        $tasks = $plan->tasks()->get()->keyBy(fn (object $t) => $t->get(KanbanCustomFieldEnum::TASK_ID->value));
        $this->assertCount(2, $tasks);
        $this->assertSame('in_progress', $tasks['t_a']->status); // running → in_progress
        $this->assertSame('pending', $tasks['t_b']->status);     // todo → pending
        $this->assertSame($agent->getId(), $tasks['t_a']->agent_id);
        $this->assertSame($agent->getId(), $tasks['t_b']->agent_id);

        // Idempotent: same board, no new plans/tasks.
        $action->board = $this->board(childAStatus: 'running');
        $action->execute();
        $this->assertSame(1, Plan::query()->where('agent_id', $agent->getId())->where('plan_type', 'hermes_kanban')->count());
        $this->assertCount(2, $plan->tasks()->get());

        // Runtime status change flows through, carrying the worker handoff + completion time.
        $action->board = $this->board(childAStatus: 'done');
        $action->execute();
        $reloaded = $plan->tasks()->get()->firstWhere(fn (object $t) => $t->get(KanbanCustomFieldEnum::TASK_ID->value) === 't_a');
        $this->assertSame('done', $reloaded->status);
        $this->assertNotNull($reloaded->completed_at);
        $this->assertSame(['KANVAS.md'], $reloaded->result['metadata']['changed_files'] ?? null);
        $this->assertSame('wrote the doc', $reloaded->result['handoff'] ?? null);
    }

    public function testDoneRootSavesOutputAndCompletedAt(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create(['name' => 'kanban-done']);

        $action = new CannedSyncDeploymentKanbanAction($this->fakeDeployment($agent, $app, $company));
        $action->board = [
            KanbanTask::parseShowPayload([
                'task' => ['id' => 't_solo', 'title' => 'one-shot', 'status' => 'done', 'completed_at' => 1780859644],
                'parents' => [],
                'children' => [],
                'latest_summary' => 'all done',
                'runs' => [['metadata' => ['changed_files' => ['OUT.md']]]],
            ]),
        ];

        $action->execute();

        $plan = Plan::query()->fromApp($app)->fromCompany($company)
            ->where('agent_id', $agent->getId())->where('plan_type', 'hermes_kanban')->first();

        $this->assertNotNull($plan);
        $this->assertSame('done', $plan->status);
        $this->assertNotNull($plan->completed_at);
        $this->assertSame('all done', $plan->output['summary'] ?? null);
        $this->assertSame(['OUT.md'], $plan->output['metadata']['changed_files'] ?? null);
    }

    public function testRefreshesKnownCardByIdEvenWhenBoardSliceMissesIt(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create(['name' => 'kanban-reassigned']);

        $action = new CannedSyncDeploymentKanbanAction($this->fakeDeployment($agent, $app, $company));

        // Initial sync discovers the card via the board slice → plan created + linked.
        $action->board = [
            KanbanTask::parseShowPayload(['task' => ['id' => 't_solo', 'title' => 'one-shot', 'status' => 'ready'], 'parents' => [], 'children' => []]),
        ];
        $action->execute();

        $plan = Plan::query()->fromApp($app)->fromCompany($company)
            ->where('agent_id', $agent->getId())->where('plan_type', 'hermes_kanban')->first();
        $this->assertNotNull($plan);
        $this->assertSame('active', $plan->status);

        // Card reassigned away → board slice is now EMPTY, but it's still reachable by id (and done).
        // Matching by task id (not assignee) must still flip the plan.
        $action->board = [];
        $action->cardsById = [
            't_solo' => KanbanTask::parseShowPayload([
                'task' => ['id' => 't_solo', 'title' => 'one-shot', 'status' => 'done', 'completed_at' => 1780859644],
                'parents' => [],
                'children' => [],
                'latest_summary' => 'finished',
            ]),
        ];

        $this->assertSame(1, $action->execute()['plans']);

        $plan->refresh();
        $this->assertSame('done', $plan->status);
        $this->assertNotNull($plan->completed_at);
    }

    /**
     * @return list<KanbanTask>
     */
    private function board(string $childAStatus): array
    {
        $childA = ['id' => 't_a', 'title' => 'research X', 'status' => $childAStatus];
        $childAExtra = ['parents' => ['t_root'], 'children' => []];

        if ($childAStatus === 'done') {
            $childA['completed_at'] = 1780859644;
            $childAExtra['latest_summary'] = 'wrote the doc';
            $childAExtra['runs'] = [['metadata' => ['changed_files' => ['KANVAS.md']]]];
        }

        return [
            KanbanTask::parseShowPayload([
                'task' => ['id' => 't_root', 'title' => 'Research the market', 'status' => 'ready'],
                'parents' => [],
                'children' => ['t_a', 't_b'],
            ]),
            KanbanTask::parseShowPayload(['task' => $childA, ...$childAExtra]),
            KanbanTask::parseShowPayload([
                'task' => ['id' => 't_b', 'title' => 'scan leads', 'status' => 'todo'],
                'parents' => ['t_root'],
                'children' => [],
            ]),
        ];
    }

    private function fakeDeployment(Agent $agent, AppInterface $app, CompanyInterface $company): AgentDeployment
    {
        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('getAttribute')->with('agent')->andReturn($agent);
        $deployment->shouldReceive('getAttribute')->with('app')->andReturn($app);
        $deployment->shouldReceive('getAttribute')->with('company')->andReturn($company);
        $deployment->shouldReceive('getId')->andReturn(987654);

        return $deployment;
    }
}

/**
 * Injects a canned board (discovery) and per-id cards (refresh) so the ingest runs without a runtime.
 */
class CannedSyncDeploymentKanbanAction extends SyncDeploymentKanbanAction
{
    /** @var list<KanbanTask> */
    public array $board = [];

    /** @var array<string, KanbanTask> */
    public array $cardsById = [];

    protected function fetchBoard(AppInterface $app, CompanyInterface $company, array $knownTaskIds = []): array
    {
        return $this->board;
    }

    protected function fetchTask(string $externalTaskId): ?KanbanTask
    {
        return $this->cardsById[$externalTaskId] ?? null;
    }
}
