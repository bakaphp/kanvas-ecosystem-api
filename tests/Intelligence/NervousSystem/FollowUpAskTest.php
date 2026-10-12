<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\OpenFollowUpTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendEmailToUserTool;
use Kanvas\NervousSystem\Plan\Actions\OpenFollowUpAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfCompletedPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * "Ping Jenn, I need the report tomorrow" from an agent with no plan of its own. The ask has to open
 * the wait, the wait has to be parked on Jenn, and closing it has to reach the person who asked —
 * without a PM, a board, or a turn of the agent's.
 */
class FollowUpAskTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'social'];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Mail::fake();
    }

    public function test_an_ask_with_nothing_to_wait_on_opens_a_follow_up_parked_on_the_teammate(): void
    {
        $agent = $this->makeAgent();
        $requester = $this->requester();
        $jenn = static::$cachedUser;
        $due = Carbon::now()->addDays(3)->setTime(10, 0);

        $result = $this->emailTool($agent, $requester)(
            recipient_email: $jenn->email,
            subject: 'Creza report',
            body: 'Could you send it by tomorrow 10:00?',
            waiting_for: 'the Creza report',
            until: $due->format('Y-m-d H:i'),
        );

        $this->assertSame('success', $result['status']);
        $task = Task::query()->findOrFail($result['task_id']);
        $this->assertSame(TaskStatusEnum::WAITING->value, $task->status);
        $this->assertSame($jenn->getId(), $task->waiting_on_users_id);
        $this->assertSame($due->toDateString(), $task->wake_at->toDateString());
        $this->assertStringContainsString('Follow-up task #' . $task->getId(), $result['guidance']);

        $plan = $task->plan;
        $this->assertSame(OpenFollowUpAction::PLAN_TYPE, $plan->plan_type);
        $this->assertSame($requester->getId(), $plan->origin_users_id);
        $this->assertSame($requester->getId(), $plan->users_id);
        $this->assertStringStartsWith('Follow-up: Get the Creza report from', $plan->title);
        $this->assertSame(1, ScheduledAction::query()->where('source_entity_id', (string) $task->getId())->where('status', 'pending')->count());
        $this->assertSame(1, Task::query()->waitingOnPerson($jenn)->whereKey($task->getId())->count());
    }

    public function test_no_date_means_tomorrow_morning_and_no_waiting_for_means_no_task(): void
    {
        $agent = $this->makeAgent();
        $tool = $this->emailTool($agent, $this->requester());

        $parked = $tool(recipient_email: static::$cachedUser->email, subject: 'Deck', body: 'Send it?', waiting_for: 'the deck');
        $plain = $tool(recipient_email: static::$cachedUser->email, subject: 'FYI', body: 'Thanks for yesterday.');

        $this->assertSame('09:00', substr($parked['wake_at'], -5));
        $this->assertArrayNotHasKey('task_id', $plain);
    }

    public function test_closing_the_follow_up_closes_its_plan_and_tells_the_requester(): void
    {
        [$task, $plan] = $this->openFollowUp();

        new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::DONE,
            result: ['note' => 'Report: https://docs.example.test/creza'],
        )->execute();

        $plan->refresh();
        $this->assertSame(PlanStatusEnum::DONE->value, $plan->status);
        $this->assertNotNull($plan->completed_at);
        $this->assertSame('Report: https://docs.example.test/creza', $task->refresh()->workerSummary());
        Bus::assertDispatched(NotifyPlanOwnerOfCompletedPlanJob::class, fn (NotifyPlanOwnerOfCompletedPlanJob $job): bool => $job->plan->getId() === $plan->getId());
        $this->assertSame(0, ScheduledAction::query()->where('source_entity_id', (string) $task->getId())->where('status', 'pending')->count());
    }

    public function test_a_blocked_follow_up_tells_the_requester_it_is_stuck(): void
    {
        [$task, $plan] = $this->openFollowUp();

        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::BLOCKED, blockedReason: 'Jenn left the company')->execute();

        $this->assertSame(PlanStatusEnum::BLOCKED->value, $plan->refresh()->status);
        $this->assertSame('Jenn left the company', $plan->error_message);
        Bus::assertDispatched(NotifyPlanOwnerOfBlockedPlanJob::class);
    }

    public function test_a_wake_that_cannot_be_booked_leaves_the_email_sent_and_says_so(): void
    {
        $result = $this->emailTool($this->makeAgent(), $this->requester())(
            recipient_email: static::$cachedUser->email,
            subject: 'Deck',
            body: 'Send it?',
            waiting_for: 'the deck',
            until: Carbon::now()->addYears(3)->format('Y-m-d H:i'),
        );

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('too far', $result['waiting_for_warning']);
        $this->assertArrayNotHasKey('task_id', $result);
    }

    public function test_open_follow_up_tracks_a_wait_the_person_handles_themselves(): void
    {
        $agent = $this->makeAgent();
        $requester = $this->requester();
        $jenn = static::$cachedUser;

        $withPerson = $this->followUpTool($agent, $requester)(waiting_for: 'the Creza report', waiting_on_email: $jenn->email);
        $alone = $this->followUpTool($agent, $requester)(waiting_for: 'the budget sign-off');

        $this->assertTrue($withPerson['success']);
        $task = Task::query()->findOrFail($withPerson['task_id']);
        $this->assertSame($jenn->getId(), $task->waiting_on_users_id);
        $this->assertSame(TaskStatusEnum::WAITING->value, $task->status);
        $this->assertSame($requester->getId(), $task->plan->origin_users_id);

        $this->assertTrue($alone['success']);
        $loneTask = Task::query()->findOrFail($alone['task_id']);
        $this->assertNull($loneTask->waiting_on_users_id);
        $this->assertStringStartsWith('Follow-up: Get the budget sign-off', $loneTask->plan->title);
        $this->assertStringContainsString('Waiting for the budget sign-off', $loneTask->blocked_reason);
    }

    public function test_open_follow_up_with_an_unknown_email_opens_the_wait_and_says_nobody_was_recorded(): void
    {
        $result = $this->followUpTool($this->makeAgent(), $this->requester())(
            waiting_for: 'the deck',
            waiting_on_email: 'nobody-' . uniqid() . '@example.test',
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('waiting_on_warning', $result);
        $this->assertNull(Task::query()->findOrFail($result['task_id'])->waiting_on_users_id);
    }

    public function test_without_a_person_to_open_it_for_the_email_still_goes_but_says_so(): void
    {
        $agent = $this->makeAgent();

        $result = new SendEmailToUserTool($agent)(
            recipient_email: static::$cachedUser->email,
            subject: 'Deck',
            body: 'Send it?',
            waiting_for: 'the deck',
        );

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('waiting_for_warning', $result);
        $this->assertArrayNotHasKey('task_id', $result);
    }

    /**
     * @return array{0: Task, 1: Plan}
     */
    private function openFollowUp(): array
    {
        $agent = $this->makeAgent();

        $result = $this->emailTool($agent, $this->requester())(
            recipient_email: static::$cachedUser->email,
            subject: 'Creza report',
            body: 'Could you send it?',
            waiting_for: 'the Creza report',
        );

        $task = Task::query()->findOrFail($result['task_id']);

        return [$task, $task->plan];
    }

    private function emailTool(Agent $agent, Users $requester): SendEmailToUserTool
    {
        return new SendEmailToUserTool($agent)->withContext(
            app(Apps::class),
            static::$cachedUser->getCurrentCompany(),
            $requester,
            $agent,
        );
    }

    private function followUpTool(Agent $agent, Users $requester): OpenFollowUpTool
    {
        return new OpenFollowUpTool()->withContext(
            app(Apps::class),
            static::$cachedUser->getCurrentCompany(),
            $requester,
            $agent,
        );
    }

    private function requester(): Users
    {
        return Users::factory()->create(['email' => 'asker-' . uniqid() . '@example.test']);
    }
}
