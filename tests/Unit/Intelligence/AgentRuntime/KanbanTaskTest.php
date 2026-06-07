<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence\AgentRuntime;

use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use PHPUnit\Framework\TestCase;

final class KanbanTaskTest extends TestCase
{
    public function testFromShowPayloadBuildsTreeAndHandoff(): void
    {
        $payload = [
            'task' => [
                'id' => 't_root',
                'title' => 'Research the market',
                'body' => null,
                'assignee' => 'researcher',
                'status' => 'ready',
                'priority' => 0,
                'tenant' => null,
                'created_at' => 1780859278,
            ],
            'latest_summary' => 'Did the research and wrote the doc.',
            'parents' => [],
            'children' => ['t_a', 't_b'],
            'runs' => [
                ['metadata' => ['changed_files' => ['OLD.md']]],
                ['metadata' => ['changed_files' => ['KANVAS-OVERVIEW.md'], 'sources' => ['https://kanvas.dev/']]],
            ],
        ];

        $task = KanbanTask::parseShowPayload($payload);

        $this->assertSame('t_root', $task->id);
        $this->assertSame('Research the market', $task->title);
        $this->assertNull($task->body);
        $this->assertSame('researcher', $task->assignee);
        $this->assertSame(KanbanStatusEnum::READY, $task->status);
        $this->assertTrue($task->isRoot());
        $this->assertSame(['t_a', 't_b'], $task->childIds);
        $this->assertSame('Did the research and wrote the doc.', $task->latestSummary);
        // newest run carrying metadata wins
        $this->assertSame(['KANVAS-OVERVIEW.md'], $task->metadata['changed_files'] ?? null);
        $this->assertSame(['https://kanvas.dev/'], $task->metadata['sources'] ?? null);
        $this->assertSame(1780859278, $task->createdAt);
    }

    public function testChildTaskIsNotRoot(): void
    {
        $task = KanbanTask::parseShowPayload([
            'task' => ['id' => 't_a', 'title' => 'research X', 'status' => 'running'],
            'parents' => ['t_root'],
            'children' => [],
        ]);

        $this->assertFalse($task->isRoot());
        $this->assertSame(['t_root'], $task->parentIds);
        $this->assertSame(KanbanStatusEnum::RUNNING, $task->status);
        $this->assertNull($task->latestSummary);
        $this->assertNull($task->metadata);
    }

    public function testFromRowIsFlatWithNoEdges(): void
    {
        $task = KanbanTask::parseFlatRow([
            'id' => 't_new',
            'title' => 'Plan: research the market',
            'assignee' => 'researcher',
            'status' => 'ready',
            'priority' => 2,
        ]);

        $this->assertSame('t_new', $task->id);
        $this->assertSame(2, $task->priority);
        $this->assertSame([], $task->parentIds);
        $this->assertSame([], $task->childIds);
        $this->assertTrue($task->isRoot());
    }

    public function testUnknownStatusFallsBackToTodo(): void
    {
        $task = KanbanTask::parseFlatRow(['id' => 't_x', 'title' => 'x', 'status' => 'wat']);

        $this->assertSame(KanbanStatusEnum::TODO, $task->status);
    }
}
