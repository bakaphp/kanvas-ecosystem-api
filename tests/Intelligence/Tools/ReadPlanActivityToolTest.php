<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ReadNervousSystemPlanActivityTool;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Going and looking, rather than explaining an absence.
 *
 * The context bundle is a summary — capped messages, recent plans, a truncated result — so an agent
 * asked about one specific plan is asking past its edge. With no way to look, the PM did not say so:
 * asked for a file its own worker had produced minutes earlier, it explained at length why no link
 * existed. The URL was on the task the whole time. This tool is the way to check.
 */
class ReadPlanActivityToolTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_it_returns_what_a_finished_task_produced(): void
    {
        $url = 'https://cdn.example.io/' . uniqid() . '.pdf';
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::DONE);
        $task->result = ['worker_summary' => 'Generated it. File URL: ' . $url];
        $task->saveQuietly();

        $result = $this->read($plan->getId());

        $this->assertSame($plan->getId(), $result['plan_id']);
        $this->assertStringContainsString($url, (string) json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    /** The bundle truncates a worker's answer; the whole point of this tool is to reach past that. */
    public function test_a_long_result_is_not_truncated_the_way_the_bundle_truncates_it(): void
    {
        $url = 'https://cdn.example.io/' . uniqid() . '.pdf';
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::DONE);
        $task->result = ['worker_summary' => str_repeat('narration. ', 200) . 'File URL: ' . $url];
        $task->saveQuietly();

        $this->assertStringContainsString(
            $url,
            (string) json_encode($this->read($plan->getId()), JSON_UNESCAPED_SLASHES),
        );
    }

    /** A blocked task's reason is the other thing someone asks about after the fact. */
    public function test_it_reports_why_a_task_stopped(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::BLOCKED);
        $task->blocked_reason = 'no tool can filter leads by missing email';
        $task->saveQuietly();

        $this->assertStringContainsString(
            'no tool can filter leads by missing email',
            (string) json_encode($this->read($plan->getId())),
        );
    }

    /** An id the model invented must come back as a handled error, never an exception in the chat. */
    public function test_an_unknown_plan_id_is_refused_without_throwing(): void
    {
        $result = $this->read(99999999);

        $this->assertArrayHasKey('error', $result);
    }

    /** A plan in another tenant is not visible however the id was obtained. */
    public function test_a_plan_outside_the_tenant_is_not_readable(): void
    {
        $plan = $this->makePlan();

        $result = new ReadNervousSystemPlanActivityTool()
            ->withContext(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)
            ->__invoke(plan_id: $plan->getId());

        $this->assertSame($plan->getId(), $result['plan_id'], 'Own-tenant plan stays readable.');

        $foreign = $this->makePlan();
        $foreign->companies_id = $foreign->companies_id + 999;
        $foreign->saveQuietly();

        $this->assertArrayHasKey('error', $this->read($foreign->getId()));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $planId): array
    {
        return new ReadNervousSystemPlanActivityTool()
            ->withContext(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)
            ->__invoke(plan_id: $planId);
    }
}
