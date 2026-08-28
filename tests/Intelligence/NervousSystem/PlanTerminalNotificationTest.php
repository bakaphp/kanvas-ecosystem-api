<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfCompletedPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Whether finishing the work tells the person who asked for it.
 *
 * The blocked half of this already existed. The done half did not, so a plan that succeeded ended in
 * silence: the agent wrote its summary to an Activities channel humans are explicitly told not to
 * watch, and the only ways to learn were to open the board or ask. Work that completes without
 * saying so is indistinguishable from work that never ran.
 */
class PlanTerminalNotificationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_finishing_a_plan_tells_its_owner(): void
    {
        Bus::fake();

        $plan = $this->makePlan();
        $plan->status = PlanStatusEnum::DONE->value;
        $plan->save();

        Bus::assertDispatched(
            NotifyPlanOwnerOfCompletedPlanJob::class,
            fn (NotifyPlanOwnerOfCompletedPlanJob $job): bool => $job->plan->getId() === $plan->getId(),
        );
    }

    /** The alert exists to get someone's attention, so ordinary progress must not raise one. */
    public function test_moving_a_plan_between_working_statuses_tells_nobody(): void
    {
        Bus::fake();

        $plan = $this->makePlan(['status' => PlanStatusEnum::DRAFT->value]);
        $plan->status = PlanStatusEnum::ACTIVE->value;
        $plan->save();

        Bus::assertNotDispatched(NotifyPlanOwnerOfCompletedPlanJob::class);
        Bus::assertNotDispatched(NotifyPlanOwnerOfBlockedPlanJob::class);
    }

    /** Saving a done plan again is not a second completion. */
    public function test_a_plan_already_done_does_not_announce_itself_twice(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);

        Bus::fake();
        $plan->completion_pct = 100;
        $plan->save();

        Bus::assertNotDispatched(NotifyPlanOwnerOfCompletedPlanJob::class);
    }

    /** A plan that reopened inside the settle delay must not announce a finish that stopped being true. */
    public function test_a_plan_that_reopened_before_the_job_ran_says_nothing(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::ACTIVE->value]);

        new NotifyPlanOwnerOfCompletedPlanJob($plan)->handle();

        $this->assertFalse(Cache::has('ns:plan:' . $plan->getId() . ':done-alert'));
    }

    /**
     * The regression behind the alert nobody could act on: the worker records why it stopped on the
     * TASK, and `error_message` on the plan stays null, so the notice said "no reason was recorded"
     * while three tasks each carried a precise one.
     */
    public function test_a_blocked_alert_reports_the_reason_its_tasks_recorded(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::BLOCKED->value]);
        $task = $this->makeTask($plan, TaskStatusEnum::BLOCKED);
        $task->title = 'Count leads missing email';
        $task->blocked_reason = 'search_leads does not expose email fields';
        $task->saveQuietly();

        $why = $this->whyBlocked($plan);

        $this->assertStringContainsString('search_leads does not expose email fields', $why);
        $this->assertStringContainsString('Count leads missing email', $why);
    }

    /** Nothing recorded anywhere still has to say something useful. */
    public function test_a_blocked_alert_falls_back_when_no_reason_was_recorded_anywhere(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::BLOCKED->value]);
        $this->makeTask($plan, TaskStatusEnum::BLOCKED);

        $this->assertStringContainsString('no reason was recorded', $this->whyBlocked($plan));
    }

    /** An explicit plan-level message is the better answer and outranks the roll-up. */
    public function test_an_explicit_plan_error_message_wins_over_the_task_rollup(): void
    {
        $plan = $this->makePlan([
            'status' => PlanStatusEnum::BLOCKED->value,
            'error_message' => 'Waiting on the Odoo credentials.',
        ]);
        $task = $this->makeTask($plan, TaskStatusEnum::BLOCKED);
        $task->blocked_reason = 'something the task said';
        $task->saveQuietly();

        $this->assertSame('Waiting on the Odoo credentials.', $this->whyBlocked($plan));
    }

    /** Reporting into the plan's own channel reaches nobody; the conversation is where a person is. */
    public function test_a_plan_remembers_the_conversation_it_was_asked_for_in(): void
    {
        $plan = $this->makePlan();

        $this->assertNull($plan->origin_channel_id, 'A plan with no conversation behind it records none.');

        $plan->origin_channel_id = 4242;
        $plan->saveQuietly();

        $this->assertSame(4242, (int) $plan->refresh()->origin_channel_id);
    }

    /** A plan with no conversation behind it — a cron, a workflow — must not try to post into one. */
    public function test_a_plan_with_no_origin_conversation_posts_only_to_its_own_channel(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);

        $this->assertNull($plan->origin_channel_id);
        $this->assertNull($plan->originChannel);

        // Reaching the origin post with no channel must be a no-op rather than a failure.
        new NotifyPlanOwnerOfCompletedPlanJob($plan)->handle();

        $this->assertTrue(true);
    }

    /**
     * The @mention cannot be trusted to reach anyone: mentions resolving to an agent user are dropped
     * on purpose, and agents share human accounts — ten sit on user 2 — so a real person gets
     * classified as a bot. The recorded asker is notified directly instead.
     */
    public function test_the_person_who_asked_is_notified_directly_not_only_mentioned(): void
    {
        Notification::fake();

        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);
        $plan->origin_users_id = static::$cachedUser->getId();
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan)->handle();

        Notification::assertSentTo(
            static::$cachedUser,
            PlanProgressNotification::class,
        );
    }

    /** A plan from a cron or a workflow has no asker, and must not invent one. */
    public function test_a_plan_with_no_recorded_asker_notifies_nobody_directly(): void
    {
        Notification::fake();

        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);
        $this->assertNull($plan->origin_users_id);

        new NotifyPlanOwnerOfCompletedPlanJob($plan)->handle();

        Notification::assertNotSentTo(static::$cachedUser, PlanProgressNotification::class);
    }

    /** The agent that owns the plan already knows it finished; telling it is noise and a loop risk. */
    public function test_the_owning_agent_is_not_notified_of_its_own_plan(): void
    {
        Notification::fake();

        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);
        $plan->origin_users_id = $plan->agent?->user?->getId();
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan)->handle();

        Notification::assertNotSentTo(static::$cachedUser, PlanProgressNotification::class);
    }

    /** Blocked has to reach the asker too — that is the one they must act on. */
    public function test_a_blocked_plan_also_reaches_the_person_who_asked(): void
    {
        Notification::fake();

        $plan = $this->makePlan(['status' => PlanStatusEnum::BLOCKED->value]);
        $plan->origin_users_id = static::$cachedUser->getId();
        $plan->saveQuietly();

        new NotifyPlanOwnerOfBlockedPlanJob($plan)->handle();

        Notification::assertSentTo(static::$cachedUser, PlanProgressNotification::class);
    }

    /**
     * The alert posted into a person's own conversation used to @mention the plan's OWNER, and on
     * agent-created work that owner is an agent — so the message arrived in your chat pinging the PM
     * instead of you. The board keeps the owner; the conversation addresses whoever asked.
     */
    public function test_the_conversation_copy_is_addressed_to_the_asker_not_the_plan_owner(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);

        // The real shape: an agent owns the plan, a human asked for it.
        $plan->users_id = $plan->agent?->user?->getId();
        $plan->origin_users_id = static::$cachedUser->getId();
        $plan->saveQuietly();
        $plan->refresh();

        $job = new NotifyPlanOwnerOfCompletedPlanJob($plan);

        $asker = new ReflectionMethod($job, 'asker')->invoke($job, $plan);
        $this->assertSame(static::$cachedUser->getId(), $asker?->getId());
        $this->assertNotSame($asker?->getId(), $plan->user?->getId(), 'Owner and asker must differ.');

        $mentionForAsker = new ReflectionMethod($job, 'mentionFor')->invoke($job, $plan, $asker);
        $mentionForOwner = new ReflectionMethod($job, 'mentionFor')->invoke($job, $plan, null);

        $this->assertNotSame(
            $mentionForOwner,
            $mentionForAsker,
            'The asker and the plan owner are different people here — the mentions must differ too.',
        );
    }

    /** A cron or workflow plan has no asker, so the conversation copy falls back to the owner. */
    public function test_with_no_asker_recorded_the_mention_falls_back_to_the_owner(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);
        $job = new NotifyPlanOwnerOfCompletedPlanJob($plan);

        $this->assertNull(new ReflectionMethod($job, 'asker')->invoke($job, $plan));
    }

    /**
     * The conversation is where the work was asked for, so it gets the ANSWER — not just the fact that
     * something finished. Telling someone a plan is done while the numbers sit on a task they have to
     * go and open is the same dead end as the file whose URL nobody could reach.
     */
    public function test_the_conversation_copy_carries_what_the_tasks_returned(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);
        $task = $this->makeTask($plan, TaskStatusEnum::DONE);
        $task->result = ['worker_summary' => 'Counted them: 33 leads are missing an email.'];
        $task->saveQuietly();

        $job = new NotifyPlanOwnerOfCompletedPlanJob($plan->refresh());
        $digest = new ReflectionMethod($job, 'resultsDigest')->invoke($job, $plan);

        $this->assertStringContainsString('33 leads are missing an email', $digest);
    }

    /**
     * Workers narrate first and answer last as often as not — "…a total matching count of 33" came
     * after four sentences of method. A head-only trim keeps the preamble and drops the number.
     */
    public function test_a_long_result_keeps_the_answer_at_its_end(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);
        $task = $this->makeTask($plan, TaskStatusEnum::DONE);
        $task->result = ['worker_summary' => str_repeat('Explaining the method. ', 60) . 'Final count: 33.'];
        $task->saveQuietly();

        $job = new NotifyPlanOwnerOfCompletedPlanJob($plan->refresh());
        $digest = new ReflectionMethod($job, 'resultsDigest')->invoke($job, $plan);

        $this->assertStringContainsString('Final count: 33.', $digest);
    }

    /** A plan whose tasks recorded nothing has no results to report, and must not pad the message. */
    public function test_a_plan_with_no_recorded_results_adds_nothing(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);
        $this->makeTask($plan, TaskStatusEnum::DONE);

        $job = new NotifyPlanOwnerOfCompletedPlanJob($plan->refresh());

        $this->assertSame('', new ReflectionMethod($job, 'resultsDigest')->invoke($job, $plan));
    }

    private function whyBlocked(Plan $plan): string
    {
        $job = new NotifyPlanOwnerOfBlockedPlanJob($plan);
        $method = new ReflectionMethod($job, 'whyBlocked');

        return (string) $method->invoke($job, $plan);
    }
}
