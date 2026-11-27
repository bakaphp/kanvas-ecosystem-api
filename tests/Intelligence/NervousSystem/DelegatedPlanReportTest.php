<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\EnsureCompanyOperationsProjectAction;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Stubs\Intelligence\ScriptedCompanyBrainAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesCompanyBrain;
use Tests\Traits\MakesEmployees;
use Tests\Traits\MakesPlans;

/**
 * What the company's assistant is woken with when work it handed off has an outcome: that plan and
 * the person who asked for it, whatever the outcome, and however many other people are waiting.
 */
final class DelegatedPlanReportTest extends TestCase
{
    use DatabaseTransactions;
    use MakesCompanyBrain;
    use MakesEmployees;
    use MakesPlans;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    private Users $max;
    private Users $jenn;
    private Project $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompanyBrain(ScriptedCompanyBrainAgentStub::class);
        $this->max = $this->makeEmployee();
        $this->jenn = $this->makeEmployee();
        $this->operations = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)
            ->execute();

        ScriptedCompanyBrainAgentStub::$reply = 'Done.';
        // CI runs the queue inline: an @mention's notification would render a mail template there.
        Notification::fake();
        ScriptedCompanyBrainAgentStub::$lastTurnUser = null;
        ScriptedCompanyBrainAgentStub::$lastUnattended = null;
    }

    #[DataProvider('outcomes')]
    public function testEveryOutcomeOfADelegatedPlanCarriesThatPlan(PlanStatusEnum $status): void
    {
        Bus::fake();
        $plan = $this->delegatedPlan($this->max);

        $this->movePlanTo($plan, $status);

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->aboutPlan?->getId() === $plan->getId()
                && $this->brain->is($job->wakeAgent),
        );
    }

    /**
     * @return array<string, array{0: PlanStatusEnum}>
     */
    public static function outcomes(): array
    {
        return [
            'blocked' => [PlanStatusEnum::BLOCKED],
            'failed' => [PlanStatusEnum::FAILED],
            'cancelled' => [PlanStatusEnum::CANCELLED],
            'done directly' => [PlanStatusEnum::DONE],
        ];
    }

    public function testARejectedPlanCarriesThatPlan(): void
    {
        Bus::fake();
        $plan = $this->delegatedPlan($this->max);
        $plan->status = PlanStatusEnum::AWAITING_APPROVAL->value;
        $plan->saveQuietly();

        new ApprovePlanAction(
            $plan->refresh(),
            $this->human,
            approved: false,
            reviewOutcome: 'Out of scope.',
        )->execute();

        Bus::assertDispatched(WakeAgentForProjectJob::class, fn (WakeAgentForProjectJob $job): bool => $job->aboutPlan?->getId() === $plan->getId());
    }

    public function testTheLastTaskFinishingStillSetsReportsOn(): void
    {
        Bus::fake();
        $plan = $this->delegatedPlan($this->max);

        new UpdateTaskStatusAction($this->taskOn($plan), TaskStatusEnum::DONE)->execute();

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->reportsOn?->getId() === $plan->getId()
                && $job->aboutPlan?->getId() === $plan->getId(),
        );
    }

    public function testBrainIsWokenForItsPlanOnSomeoneElsesProject(): void
    {
        Bus::fake();
        $plan = $this->delegatedPlan($this->jenn, project: $this->projectManagedBy($this->makeAgent()));

        $this->movePlanTo($plan, PlanStatusEnum::BLOCKED);

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $this->brain->is($job->wakeAgent)
                && $job->aboutPlan?->getId() === $plan->getId(),
        );
    }

    public function testTwoOutcomesOnTheOperationsBoardDoNotShareALockOrASession(): void
    {
        $jobA = $this->outcomeJob($planA = $this->delegatedPlan($this->max));
        $jobB = $this->outcomeJob($this->delegatedPlan($this->jenn));

        $this->assertSame('plan-wake-' . $planA->getId(), $this->lock($jobA)->key);
        $this->assertNotSame($this->lock($jobA)->key, $this->lock($jobB)->key);
        $this->assertNotSame($this->sessionOf($jobA)->getId(), $this->sessionOf($jobB)->getId());
    }

    public function testAPmsOwnProjectOutcomeKeepsTheProjectLockAndStillDropsOnCollision(): void
    {
        $project = $this->projectManagedBy($this->makeAgent());
        $plan = $this->makePlan(['project_id' => $project->getId(), 'created_by_agent_id' => $project->agent_id]);
        $job = new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            'The plan is blocked.',
            wakeAgent: $project->pmAgent,
            aboutPlan: $plan,
        );

        $this->assertSame('project-wake-' . $project->getId(), $this->lock($job)->key);
        $this->assertNull($this->lock($job)->releaseAfter);
        $this->assertStringContainsString('You are the PM of this project', $this->messageOf($job));
    }

    public function testTheOutcomePromptCarriesOnlyItsOwnPlan(): void
    {
        $planA = $this->delegatedPlan($this->max, title: 'Invoice for Acme');
        $this->delegatedPlan($this->jenn, title: 'Invoice for Globex');

        $message = $this->messageOf($this->outcomeJob($planA));

        $this->assertStringContainsString('Invoice for Acme', $message);
        $this->assertStringNotContainsString('Invoice for Globex', $message);
        $this->assertStringNotContainsString('You are the PM of this project', $message);
        $this->assertStringContainsString('You handed this work to another agent', $message);
    }

    public function testABlockedOutcomeIsScopedLikeAFinishedOneAndRunsAsThePersonWhoAsked(): void
    {
        $plan = $this->delegatedPlan($this->jenn, title: 'Invoice for Globex');
        $job = $this->outcomeJob($plan, finished: false);

        [$user, $human, $unattended] = $this->identityOf($job);

        $this->assertSame('plan-wake-' . $plan->getId(), $this->lock($job)->key);
        $this->assertTrue($this->jenn->is($user));
        $this->assertNull($human);
        $this->assertTrue($unattended);
        $this->assertStringContainsString('Invoice for Globex', $this->messageOf($job));
    }

    public function testAnOutcomeWithNoRecordedAskerFallsBackToTheBoardsUser(): void
    {
        [$user] = $this->identityOf($this->outcomeJob($this->delegatedPlan(null)));

        $this->assertTrue($this->operations->owner->is($user));
    }

    public function testAnOperationsHeartbeatIsNotFramedAsAPm(): void
    {
        $message = $this->messageOf(new WakeAgentForProjectJob($this->operations, WakeAgentForProjectJob::REASON_HEARTBEAT));

        $this->assertStringContainsString('This is your Operations board', $message);
        $this->assertStringNotContainsString('You are the PM of this project', $message);
    }

    public function testTheWakeRunsAsTheAskerAndUnattended(): void
    {
        $plan = $this->delegatedPlan($this->jenn, $this->sessionWithBrain($this->jenn));

        $this->outcomeJob($plan, finished: false)->handle();

        $this->assertTrue($this->jenn->is(ScriptedCompanyBrainAgentStub::$lastTurnUser));
        $this->assertTrue(ScriptedCompanyBrainAgentStub::$lastUnattended);
    }

    public function testAFinishedAnswerInASharedChannelMentionsWhoAsked(): void
    {
        $shared = $this->sessionWithBrain($this->human, $this->channelFor($this->human, 'Team'));
        $plan = $this->delegatedPlan($this->jenn, $shared);
        ScriptedCompanyBrainAgentStub::$reply = 'Invoice #123 is ready.';

        Event::fake([AgentChatResponseEvent::class]);

        $this->outcomeJob($plan)->handle();

        $this->assertSame(1, $this->messagesIn($shared, 'Invoice #123 is ready.'));
        $this->assertSame(1, $this->messagesIn($shared, '@' . $this->jenn->getAppDisplayName()));
        Event::assertDispatched(AgentChatResponseEvent::class);
    }

    private function delegatedPlan(
        ?Users $asker,
        ?Session $session = null,
        string $title = 'Prepare an invoice',
        ?Project $project = null,
    ): Plan {
        return $this->makePlan([
            'project_id' => ($project ?? $this->operations)->getId(),
            'title' => $title,
            'created_by_agent_id' => $this->brain->getId(),
            'origin_users_id' => $asker?->getId(),
            'origin_session_id' => $session?->getId(),
            'origin_channel_id' => $session?->channel_id,
        ])->refresh();
    }

    private function taskOn(Plan $plan): Task
    {
        return Task::create([
            'plan_id' => $plan->getId(),
            'apps_id' => $plan->apps_id,
            'companies_id' => $plan->companies_id,
            'agent_id' => $plan->agent_id,
            'sequence' => 0,
            'title' => 'Research ' . fake()->unique()->lexify('?????'),
            'status' => TaskStatusEnum::IN_PROGRESS->value,
            'is_deleted' => 0,
        ]);
    }

    private function outcomeJob(Plan $plan, bool $finished = true): WakeAgentForProjectJob
    {
        return new WakeAgentForProjectJob(
            $plan->project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            $finished ? 'Every task is finished.' : 'The plan is blocked.',
            wakeAgent: $this->brain,
            reportsOn: $finished ? $plan : null,
            aboutPlan: $plan,
        );
    }

    private function lock(WakeAgentForProjectJob $job): WithoutOverlapping
    {
        foreach ($job->middleware() as $middleware) {
            if ($middleware instanceof WithoutOverlapping) {
                return $middleware;
            }
        }

        $this->fail('The wake has no overlap lock.');
    }

    private function sessionOf(WakeAgentForProjectJob $job): Session
    {
        return new ReflectionMethod($job, 'resolveSession')->invoke($job);
    }

    private function messageOf(WakeAgentForProjectJob $job): string
    {
        return new ReflectionMethod($job, 'buildMessage')->invoke($job);
    }

    /**
     * @return array{0: Users, 1: ?Users, 2: bool}
     */
    private function identityOf(WakeAgentForProjectJob $job): array
    {
        return new ReflectionMethod($job, 'turnIdentity')->invoke($job, $this->brain, $job->project->owner);
    }
}
