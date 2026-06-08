<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Contracts\AgentRuntimeProvider;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\Intelligence\AgentRuntime\Providers\AbstractAgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Jobs\PushCommentToKanbanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Mockery;
use Tests\TestCase;

/**
 * The Kanvas → Hermes comment half: a human comment is pushed to the card tagged `kanvas:<uid>`,
 * and when the card is blocked (the agent escalated) the push also unblocks it so the worker
 * re-spawns and resumes. No card link → nothing pushed.
 */
final class PushCommentToKanbanJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testBlockedCardGetsCommentAndUnblock(): void
    {
        $plan = $this->hermesPlan('blocked');
        $fake = new RecordingCommentProvider();

        new TestablePushCommentToKanbanJob($plan, 'use the staging key', 42, $fake)->handle();

        $this->assertSame(
            [['t_root', 'use the staging key', 'kanvas:42']],
            $fake->comments,
        );
        $this->assertSame(['t_root'], $fake->unblocked);
        $this->assertSame('ready', $plan->get(KanbanCustomFieldEnum::STATUS->value));
    }

    public function testNonBlockedCardGetsCommentOnly(): void
    {
        $plan = $this->hermesPlan('running');
        $fake = new RecordingCommentProvider();

        new TestablePushCommentToKanbanJob($plan, 'fyi', 7, $fake)->handle();

        $this->assertCount(1, $fake->comments);
        $this->assertSame([], $fake->unblocked);
    }

    public function testNoCardLinkPushesNothing(): void
    {
        $plan = $this->hermesPlan(null); // no TASK_ID set
        $fake = new RecordingCommentProvider();

        new TestablePushCommentToKanbanJob($plan, 'hello', 1, $fake)->handle();

        $this->assertSame([], $fake->comments);
        $this->assertSame([], $fake->unblocked);
    }

    private function hermesPlan(?string $kanbanStatus): Plan
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'kanban-push', 'user_id' => $user->getId()]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'push-comment plan',
                planType: 'hermes_kanban',
                agent: $agent,
            ),
            fromSync: true,
        )->execute();

        if ($kanbanStatus !== null) {
            $plan->set(KanbanCustomFieldEnum::TASK_ID->value, 't_root');
            $plan->set(KanbanCustomFieldEnum::STATUS->value, $kanbanStatus);
        }

        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('isRunning')->andReturn(true);
        $deployment->shouldReceive('getAttribute')->with('provider')->andReturn('hermes');

        $agentMock = Mockery::mock(Agent::class)->makePartial();
        $agentMock->shouldReceive('getAttribute')->with('activeDeployment')->andReturn($deployment);
        $plan->setRelation('agent', $agentMock);

        return $plan;
    }
}

class RecordingCommentProvider extends AbstractAgentRuntimeProvider
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $comments = [];
    /** @var list<string> */
    public array $unblocked = [];

    public function name(): AgentProviderEnum
    {
        return AgentProviderEnum::HERMES;
    }

    public function commentKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        string $text,
        string $author,
        ?string $board = null,
    ): void {
        $this->comments[] = [$externalTaskId, $text, $author];
    }

    public function transitionKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        KanbanTransition $transition,
        ?string $reason = null,
        ?string $assignee = null,
        ?string $result = null,
        ?string $board = null,
    ): KanbanTask {
        if ($transition === KanbanTransition::UNBLOCK) {
            $this->unblocked[] = $externalTaskId;
        }

        return KanbanTask::parseShowPayload([
            'task' => ['id' => $externalTaskId, 'title' => 'x', 'status' => 'ready'],
            'parents' => [],
            'children' => [],
        ]);
    }
}

class TestablePushCommentToKanbanJob extends PushCommentToKanbanJob
{
    public function __construct(
        Plan $plan,
        string $content,
        int $authorUserId,
        private readonly AgentRuntimeProvider $fake,
    ) {
        parent::__construct($plan, $content, $authorUserId);
    }

    protected function provider(AgentDeployment $deployment): AgentRuntimeProvider
    {
        return $this->fake;
    }
}
