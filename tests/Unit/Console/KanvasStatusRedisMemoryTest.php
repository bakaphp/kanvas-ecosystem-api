<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\KanvasStatusCommand;
use ReflectionMethod;
use Tests\TestCase;

class KanvasStatusRedisMemoryTest extends TestCase
{
    public function testUsageIsMeasuredAgainstMaxMemory(): void
    {
        $memory = new ReflectionMethod(KanvasStatusCommand::class, 'redisMemory')->invoke(
            new KanvasStatusCommand(),
            [
                'used_memory' => 9_073_118_208,
                'maxmemory' => 10_415_295_693,
                'total_system_memory' => 16_000_000_000,
            ]
        );

        $this->assertSame(87.1, $memory['percent']);
        $this->assertSame(10_415_295_693, $memory['limit']);
        $this->assertTrue($memory['has_max_memory']);
    }

    public function testWithoutMaxMemoryTheHostMemoryIsTheLimit(): void
    {
        $memory = new ReflectionMethod(KanvasStatusCommand::class, 'redisMemory')->invoke(
            new KanvasStatusCommand(),
            [
                'used_memory' => 250,
                'maxmemory' => 0,
                'total_system_memory' => 1000,
            ]
        );

        $this->assertSame(25.0, $memory['percent']);
        $this->assertFalse($memory['has_max_memory']);
    }

    public function testAnUnknownLimitIsNotReportedAsEmpty(): void
    {
        $memory = new ReflectionMethod(KanvasStatusCommand::class, 'redisMemory')->invoke(
            new KanvasStatusCommand(),
            ['used_memory' => 250]
        );

        $this->assertNull($memory['percent']);
        $this->assertSame(250, $memory['used']);
    }

    public function testKeyspaceRowsNameTheConnectionsOnEachDatabase(): void
    {
        config(['database.redis' => [
            'client' => 'phpredis',
            'options' => ['prefix' => 'x'],
            'default' => ['database' => '0'],
            'graph-cache' => ['database' => 4],
        ]]);

        $rows = new ReflectionMethod(KanvasStatusCommand::class, 'redisKeyspace')->invoke(
            new KanvasStatusCommand(),
            [
                'used_memory' => 1,
                'db0' => 'keys=2268381,expires=1134190,avg_ttl=10,subexpiry=0',
                'db4' => 'keys=729,expires=0,avg_ttl=0',
                'db9' => 'keys=0,expires=0',
            ]
        );

        $this->assertSame(
            [
                [
                    '0',
                    'default',
                    '2,268,381',
                    '1,134,190 (50%)',
                ],
                [
                    '4',
                    'graph-cache',
                    '729',
                    '0 (0%)',
                ],
                [
                    '9',
                    '-',
                    '0',
                    '0 (0%)',
                ],
            ],
            $rows
        );
    }
}
