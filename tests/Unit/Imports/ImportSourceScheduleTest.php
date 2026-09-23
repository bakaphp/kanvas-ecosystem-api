<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Carbon\Carbon;
use Kanvas\Imports\Models\ImportConnection;
use Kanvas\Imports\Models\ImportSource;
use Tests\TestCaseUnit;

class ImportSourceScheduleTest extends TestCaseUnit
{
    public function testDueOnceTheScheduledTimeInItsTimezonePasses(): void
    {
        // 01:00 New York (EDT, UTC-4) is 05:00 UTC.
        $source = $this->source(lastRunAt: '2026-09-21 05:10:00');

        $this->assertFalse($source->isDue(Carbon::parse('2026-09-22 04:59:00', 'UTC')));
        $this->assertTrue($source->isDue(Carbon::parse('2026-09-22 05:00:00', 'UTC')));
    }

    public function testNotDueAgainAfterItRanForThatSlot(): void
    {
        $source = $this->source(lastRunAt: '2026-09-22 05:00:30');

        $this->assertFalse($source->isDue(Carbon::parse('2026-09-22 05:15:00', 'UTC')));
    }

    public function testAMissedTickStillRunsOnceLater(): void
    {
        $source = $this->source(lastRunAt: '2026-09-21 05:00:00');

        $this->assertTrue($source->isDue(Carbon::parse('2026-09-22 09:45:00', 'UTC')));
    }

    public function testANewSourceWaitsForItsFirstScheduledTime(): void
    {
        $source = $this->source(lastRunAt: null, createdAt: '2026-09-22 14:00:00');

        $this->assertFalse($source->isDue(Carbon::parse('2026-09-22 20:00:00', 'UTC')));
        $this->assertTrue($source->isDue(Carbon::parse('2026-09-23 05:00:00', 'UTC')));
    }

    public function testOwnScheduleOverridesTheConnectionAndInactiveNeverRuns(): void
    {
        $source = $this->source(lastRunAt: '2026-09-21 12:00:00');
        $source->schedule = '0 6 * * *';
        $source->timezone = 'UTC';

        $this->assertFalse($source->isDue(Carbon::parse('2026-09-22 05:30:00', 'UTC')));
        $this->assertTrue($source->isDue(Carbon::parse('2026-09-22 06:00:00', 'UTC')));

        $source->is_active = false;
        $this->assertFalse($source->isDue(Carbon::parse('2026-09-22 06:00:00', 'UTC')));
    }

    public function testNoScheduleAnywhereMeansOnlyForcedRuns(): void
    {
        $source = $this->source(lastRunAt: null, connectionSchedule: null);

        $this->assertFalse($source->isDue(Carbon::parse('2026-09-23 05:00:00', 'UTC')));
    }

    private function source(
        ?string $lastRunAt,
        string $createdAt = '2026-01-01 00:00:00',
        ?string $connectionSchedule = '0 1 * * *'
    ): ImportSource {
        $connection = new ImportConnection([
            'default_schedule' => $connectionSchedule,
            'timezone' => 'America/New_York',
        ]);

        $source = new ImportSource(['is_active' => true]);
        $source->last_run_at = $lastRunAt !== null ? Carbon::parse($lastRunAt, 'UTC') : null;
        $source->created_at = Carbon::parse($createdAt, 'UTC');
        $source->setRelation('importConnection', $connection);

        return $source;
    }
}
