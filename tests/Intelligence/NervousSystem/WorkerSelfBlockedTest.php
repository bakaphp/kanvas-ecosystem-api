<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\RunTaskWorkerJob;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A worker that says it could not do the work must not close its own task.
 *
 * The people-audit task answered "### Task Status Report: Blocked — the health report does not expose
 * that metric" and was marked `done` anyway. The orchestrator then reported the count as 0, because a
 * done task with no contradiction reads as an answer. Nobody could tell that number apart from the two
 * beside it that were genuinely measured.
 *
 * The opposite error is worse, though: marking finished work blocked throws away work that succeeded.
 * So detection is anchored to how the answer OPENS, and to headline forms only.
 */
class WorkerSelfBlockedTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    #[DataProvider('blockedOpenings')]
    public function test_an_answer_that_opens_by_reporting_a_block_is_recognised(string $response): void
    {
        $this->assertTrue($this->reportsBlocked($response), sprintf('Should read as blocked: %s', $response));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function blockedOpenings(): array
    {
        return [
            'bare marker' => ['BLOCKED: no tool exposes that field.'],
            'markdown heading' => ['### Task Status Report: Blocked'],
            'the real one' => ["### Task Status Report: Blocked\n\n**Task Objective:** Count people…"],
            'bold marker' => ['**BLOCKED** — the health report does not track it.'],
            'plain sentence' => ['Unable to complete: the CRM has no such filter.'],
            'first person' => ['I was unable to determine the count.'],
        ];
    }

    #[DataProvider('successfulAnswers')]
    public function test_a_successful_answer_is_never_read_as_blocked(string $response): void
    {
        $this->assertFalse($this->reportsBlocked($response), sprintf('Should read as done: %s', $response));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function successfulAnswers(): array
    {
        return [
            'plain result' => ['Counted them: 33 leads are missing an email.'],
            'mentions blocking mid-text' => ['I unblocked the pipeline, then counted 33 leads.'],
            'reports someone else blocked' => ['The earlier run was blocked, but this one completed: 130 found.'],
            'narrates then answers' => ["### Summary of Findings\n\nTotal leads missing email: 33."],
            'word appears late' => [str_repeat('Counting rows. ', 40) . 'No blockers were hit.'],
        ];
    }

    /** The block has to reach the board, not just the log — that is what the orchestrator reads. */
    public function test_a_self_blocked_worker_leaves_the_task_blocked_with_its_own_words(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);

        $job = new RunTaskWorkerJob($task);
        new ReflectionMethod($job, 'markBlocked')
            ->invoke($job, 'BLOCKED: no tool exposes people-to-lead links.', true);

        $task->refresh();

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('no tool exposes people-to-lead links', (string) $task->blocked_reason);
        // Kept as the result too, so the reason's 500-char cap doesn't lose the detail that makes it
        // actionable — the same text the conversation copy quotes back.
        $this->assertStringContainsString(
            'no tool exposes people-to-lead links',
            (string) (is_array($task->result) ? ($task->result['worker_summary'] ?? '') : ''),
        );
    }

    private function reportsBlocked(string $response): bool
    {
        $job = new RunTaskWorkerJob($this->makeTask($this->makePlan(), TaskStatusEnum::IN_PROGRESS));

        return (bool) new ReflectionMethod($job, 'reportsItselfBlocked')->invoke($job, $response);
    }
}
