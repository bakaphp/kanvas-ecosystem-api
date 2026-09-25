<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessDiff;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessUsage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\TestCase;

class HarnessDtoTest extends TestCase
{
    public function testDiffSummarisesFileCountAndLineTotals(): void
    {
        $diff = $this->diff();

        $this->assertFalse($diff->isEmpty());
        $this->assertSame('3 file(s), +19/-6', $diff->summary());
        $this->assertSame(
            ['src/Domains/Foo/Bar.php', 'config/app.php', 'tests/FooTest.php'],
            $diff->paths()
        );
    }

    public function testEmptyDiffReportsNoChanges(): void
    {
        $diff = new HarnessDiff();

        $this->assertTrue($diff->isEmpty());
        $this->assertSame('no changes', $diff->summary());
        $this->assertSame([], $diff->paths());
    }

    public function testTouchedProtectedPathsMatchesPrefixesRegardlessOfTrailingSlash(): void
    {
        $diff = $this->diff();

        $this->assertSame(['config/app.php'], $diff->touchedProtectedPaths(['config/']));
        $this->assertSame(['config/app.php'], $diff->touchedProtectedPaths(['config']));
        $this->assertSame(
            ['src/Domains/Foo/Bar.php', 'config/app.php'],
            $diff->touchedProtectedPaths(['config/', 'src/Domains/'])
        );
        $this->assertSame([], $diff->touchedProtectedPaths(['database/migrations/']));
        $this->assertSame([], $diff->touchedProtectedPaths([]));
    }

    public function testTouchedProtectedPathsReportsEachHitOnce(): void
    {
        $diff = $this->diff();

        // Both prefixes match the same file; the inner loop breaks so it is not double-counted.
        $this->assertSame(['config/app.php'], $diff->touchedProtectedPaths(['config/', 'config/app']));
    }

    public function testUsagePlusAddsEveryBucketAndTotalCountsOnlyInputAndOutput(): void
    {
        $sum = new HarnessUsage(
            inputTokens: 10,
            outputTokens: 5,
            cacheReadTokens: 3,
            cacheWriteTokens: 2,
            reasoningTokens: 1,
        )->plus(new HarnessUsage(
            inputTokens: 4,
            outputTokens: 6,
            cacheReadTokens: 7,
            cacheWriteTokens: 8,
            reasoningTokens: 9,
        ));

        $this->assertSame(14, $sum->inputTokens);
        $this->assertSame(11, $sum->outputTokens);
        $this->assertSame(10, $sum->cacheReadTokens);
        $this->assertSame(10, $sum->cacheWriteTokens);
        $this->assertSame(10, $sum->reasoningTokens);
        $this->assertSame(25, $sum->totalTokens());
    }

    public function testUsagePlusIsImmutable(): void
    {
        $base = new HarnessUsage(inputTokens: 10, outputTokens: 5);
        $base->plus(new HarnessUsage(inputTokens: 1, outputTokens: 1));

        $this->assertSame(10, $base->inputTokens);
        $this->assertSame(5, $base->outputTokens);
    }

    public function testPromptPutsPolicyFirstAndTaskLastAndSkipsBlankBlocks(): void
    {
        $text = new HarnessPrompt(
            task: 'TASK: ship it',
            policy: 'POLICY: do no harm',
            repoRules: 'RULES: use tabs',
            persona: 'PERSONA: terse',
            memories: '   ',
            handoff: null,
        )->toText();

        $this->assertSame(
            "POLICY: do no harm\n\n---\n\nRULES: use tabs\n\n---\n\nPERSONA: terse\n\n---\n\nTASK: ship it",
            $text
        );
    }

    public function testPromptKeepsEveryBlockInTheDocumentedOrder(): void
    {
        $text = new HarnessPrompt(
            task: 'task',
            policy: 'policy',
            repoRules: 'rules',
            persona: 'persona',
            memories: 'memories',
            handoff: 'handoff',
        )->toText();

        $this->assertSame(
            ['policy', 'rules', 'memories', 'handoff', 'persona', 'task'],
            explode("\n\n---\n\n", $text)
        );
    }

    public function testPromptWithOnlyPolicyAndTaskHasNoStraySeparators(): void
    {
        $text = new HarnessPrompt(task: 'task', policy: 'policy')->toText();

        $this->assertSame("policy\n\n---\n\ntask", $text);
    }

    public function testSubstitutedModelReturnsTheUnexpectedModelOrNull(): void
    {
        $matching = $this->tick(['gpt-4.1']);
        $this->assertNull($matching->substitutedModel(['gpt-4.1', 'gpt-4.1-mini']));

        $swapped = $this->tick(['gpt-4.1', 'anthropic/claude-sonnet']);
        $this->assertSame('anthropic/claude-sonnet', $swapped->substitutedModel(['gpt-4.1']));

        $this->assertNull($this->tick([])->substitutedModel(['gpt-4.1']));
    }

    public function testWaitingStatusesStayInProgressBecauseBlockedIsTerminal(): void
    {
        $this->assertSame(
            TaskStatusEnum::IN_PROGRESS,
            HarnessStatusEnum::AWAITING_PERMISSION->toTaskStatus()
        );
        $this->assertSame(
            TaskStatusEnum::IN_PROGRESS,
            HarnessStatusEnum::AWAITING_ANSWER->toTaskStatus()
        );

        $this->assertTrue(HarnessStatusEnum::AWAITING_PERMISSION->isWaitingOnAHuman());
        $this->assertTrue(HarnessStatusEnum::AWAITING_ANSWER->isWaitingOnAHuman());
        $this->assertFalse(HarnessStatusEnum::AWAITING_PERMISSION->isTerminal());
        $this->assertFalse(HarnessStatusEnum::AWAITING_ANSWER->isTerminal());
    }

    public function testEveryHarnessStatusMapsToTheExpectedTaskStatus(): void
    {
        $expected = [
            'starting' => TaskStatusEnum::IN_PROGRESS,
            'running' => TaskStatusEnum::IN_PROGRESS,
            'idle' => TaskStatusEnum::IN_PROGRESS,
            'awaiting_answer' => TaskStatusEnum::IN_PROGRESS,
            'awaiting_permission' => TaskStatusEnum::IN_PROGRESS,
            'completed' => TaskStatusEnum::DONE,
            'failed' => TaskStatusEnum::BLOCKED,
            'cancelled' => TaskStatusEnum::SKIPPED,
        ];

        foreach (HarnessStatusEnum::cases() as $status) {
            $this->assertArrayHasKey($status->value, $expected);
            $this->assertSame($expected[$status->value], $status->toTaskStatus(), $status->value);
        }
    }

    public function testOnlyCompletedFailedAndCancelledAreTerminal(): void
    {
        $terminal = array_values(array_filter(
            HarnessStatusEnum::cases(),
            static fn (HarnessStatusEnum $status): bool => $status->isTerminal()
        ));

        $this->assertSame(
            [HarnessStatusEnum::COMPLETED, HarnessStatusEnum::FAILED, HarnessStatusEnum::CANCELLED],
            $terminal
        );
    }

    private function diff(): HarnessDiff
    {
        return new HarnessDiff([
            ['file' => 'src/Domains/Foo/Bar.php', 'additions' => 10, 'deletions' => 2, 'status' => 'modified'],
            ['file' => 'config/app.php', 'additions' => 4, 'deletions' => 4, 'status' => 'modified'],
            ['file' => 'tests/FooTest.php', 'additions' => 5, 'deletions' => 0, 'status' => 'added'],
        ]);
    }

    /**
     * @param list<string> $modelsObserved
     */
    private function tick(array $modelsObserved): HarnessTick
    {
        return new HarnessTick(
            status: HarnessStatusEnum::RUNNING,
            usage: new HarnessUsage(),
            modelsObserved: $modelsObserved,
        );
    }
}
