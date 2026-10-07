<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\KanvasStatusCommand;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A pending count alone cannot tell a draining queue from a stuck one, so the status table also shows
 * how long the head job has waited, read off the Redis payload's `createdAt`.
 */
class KanvasStatusOldestWaitingTest extends TestCase
{
    public function testTheWaitIsRenderedInTheUnitThatReads(): void
    {
        $age = new ReflectionMethod(KanvasStatusCommand::class, 'age');
        $command = new KanvasStatusCommand();

        $this->assertSame('-', $age->invoke($command, null));
        $this->assertSame('45s', $age->invoke($command, 45));
        $this->assertSame('6m', $age->invoke($command, 400));
        $this->assertSame('2h 5m', $age->invoke($command, 7_500));
    }

    public function testANonRedisQueueHasNoWaitToReport(): void
    {
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->andReturn(Mockery::mock(Queue::class));

        $oldest = new ReflectionMethod(KanvasStatusCommand::class, 'oldestWaitingSeconds');
        $running = new ReflectionMethod(KanvasStatusCommand::class, 'runningCount');

        $this->assertNull($oldest->invoke(new KanvasStatusCommand(), $factory, 'agent-chat'));
        $this->assertNull($running->invoke(new KanvasStatusCommand(), $factory, 'agent-chat'));
    }
}
