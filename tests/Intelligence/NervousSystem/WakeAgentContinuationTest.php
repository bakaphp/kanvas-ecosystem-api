<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\NervousSystem\Plan\Actions\PlanContinuationAction;
use Kanvas\NervousSystem\Plan\Enums\PlanLoopConfigEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\PlanLoopSettings;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * The message the agent actually receives. Asserted without an LLM by driving `buildMessage()`
 * directly — the whole reason the decision is a separate pure action.
 */
class WakeAgentContinuationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_the_loop_is_off_unless_the_agent_opts_in(): void
    {
        $this->assertFalse(PlanLoopSettings::continuationEnabled($this->makeAgent()));
    }

    public function test_an_agent_flag_turns_the_loop_on(): void
    {
        $agent = $this->makeAgent();
        $agent->set(PlanLoopConfigEnum::CONTINUATION_ENABLED->value, '1');

        $this->assertTrue(PlanLoopSettings::continuationEnabled($agent->refresh()));
    }

    /** The prose that used to be the loop's control flow must be gone when the loop is on. */
    public function test_the_verdict_replaces_the_old_prose_instruction(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $message = $this->buildMessage($plan, WakeAgentForPlanJob::REASON_TASK_COMPLETED, 'Task 3 finished.');

        $this->assertStringContainsString('verdict=dispatch', $message);
        $this->assertStringContainsString('Do NOT close the plan', $message);
        $this->assertStringNotContainsString('close the plan if the work is finished', $message);
    }

    public function test_a_completed_plan_is_told_to_verify_rather_than_dispatch(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::DONE);

        $message = $this->buildMessage($plan, WakeAgentForPlanJob::REASON_TASK_COMPLETED, 'Task 1 finished.');

        $this->assertStringContainsString('verdict=verify', $message);
        $this->assertStringContainsString('against the original objective', $message);
    }

    /** A human asking a question must not be answered with a board instruction. */
    public function test_a_human_comment_leads_the_message_ahead_of_the_verdict(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $message = $this->buildMessage($plan, WakeAgentForPlanJob::REASON_COMMENT, 'Can we skip the second step?');

        $comment = strpos($message, 'Can we skip the second step?');
        $verdictBody = strpos($message, 'Plan state:');

        $this->assertNotFalse($comment);
        $this->assertNotFalse($verdictBody);
        $this->assertLessThan($verdictBody, $comment);
    }

    public function test_an_exhausted_plan_is_told_to_stop(): void
    {
        $plan = $this->makePlan(['wake_count' => 30, 'max_wakes' => 5]);
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $message = $this->buildMessage($plan, WakeAgentForPlanJob::REASON_TASK_COMPLETED, null);

        $this->assertStringContainsString('verdict=abandon', $message);
        $this->assertStringContainsString('exhausted its budget', $message);
    }

    /** With the flag off the message must be byte-identical to what shipped before. */
    public function test_the_old_message_is_untouched_when_the_loop_is_off(): void
    {
        $plan = $this->makePlan();

        $method = new ReflectionMethod(WakeAgentForPlanJob::class, 'buildMessage');
        $message = $method->invoke(
            new WakeAgentForPlanJob($plan, WakeAgentForPlanJob::REASON_TASK_COMPLETED, 'Task 1 finished.'),
            null,
        );

        $this->assertStringContainsString('[NS:task_completed]', $message);
        $this->assertStringContainsString('close the plan if the work is finished', $message);
    }

    private function buildMessage(Plan $plan, string $reason, ?string $userMessage): string
    {
        $decision = new PlanContinuationAction($plan)->execute();

        $method = new ReflectionMethod(WakeAgentForPlanJob::class, 'buildMessage');

        return (string) $method->invoke(new WakeAgentForPlanJob($plan, $reason, $userMessage), $decision);
    }
}
