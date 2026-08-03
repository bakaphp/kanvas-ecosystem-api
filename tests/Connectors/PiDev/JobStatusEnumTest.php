<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Kanvas\Connectors\PiDev\Enums\JobStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\TestCase;

final class JobStatusEnumTest extends TestCase
{
    public function testMapsPiDevLifecycleOntoTaskStatus(): void
    {
        // failed → blocked (the only Task error status), cancelled → skipped — no first-class Task equivalents.
        $this->assertSame(TaskStatusEnum::PENDING, JobStatusEnum::QUEUED->toTaskStatus());
        $this->assertSame(TaskStatusEnum::IN_PROGRESS, JobStatusEnum::RUNNING->toTaskStatus());
        $this->assertSame(TaskStatusEnum::DONE, JobStatusEnum::COMPLETED->toTaskStatus());
        $this->assertSame(TaskStatusEnum::BLOCKED, JobStatusEnum::FAILED->toTaskStatus());
        $this->assertSame(TaskStatusEnum::SKIPPED, JobStatusEnum::CANCELLED->toTaskStatus());
    }

    public function testTerminalFlags(): void
    {
        $this->assertTrue(JobStatusEnum::COMPLETED->isTerminal());
        $this->assertTrue(JobStatusEnum::FAILED->isTerminal());
        $this->assertTrue(JobStatusEnum::CANCELLED->isTerminal());
        $this->assertFalse(JobStatusEnum::QUEUED->isTerminal());
        $this->assertFalse(JobStatusEnum::RUNNING->isTerminal());
    }
}
