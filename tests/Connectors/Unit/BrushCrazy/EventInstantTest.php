<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Support\EventInstant;
use PHPUnit\Framework\TestCase;

/**
 * Columbus OH (~8,100 rows, 37% of the dataset) is the only studio outside America/Denver, so it is
 * the only place the source's hardcoded-zone bug shows up. These tests pin both semantics.
 */
final class EventInstantTest extends TestCase
{
    private const CUTOFF = '2026-08-21 00:00:00';

    private function cutoff(): Carbon
    {
        return Carbon::parse(self::CUTOFF, 'UTC');
    }

    private function resolve(?string $rawStart, ?string $rawEnd, string $studioTimezone): ?EventInstant
    {
        return EventInstant::resolve(
            $rawStart,
            $rawEnd,
            $studioTimezone,
            $this->cutoff()
        );
    }

    /** A Denver studio round-trips untouched — the source zone already matches reality. */
    public function testDenverStudioIsNeverCorrected(): void
    {
        $instant = $this->resolve('2026-09-15 01:00:00', '2026-09-15 04:00:00', 'America/Denver');

        $this->assertNotNull($instant);
        $this->assertFalse($instant->corrected);
        $this->assertSame('2026-09-15 01:00:00', $instant->startAt->format('Y-m-d H:i:s'));
        $this->assertSame('19:00:00', $instant->localStartTime());
    }

    /**
     * 01:00 UTC is 19:00 Denver — the wall clock staff entered. Re-read in Eastern that is
     * 23:00 UTC, a two-hour shift, and the displayed 19:00 is preserved.
     */
    public function testFutureColumbusRowIsCorrectedButKeepsItsWallClock(): void
    {
        $instant = $this->resolve('2026-09-15 01:00:00', '2026-09-15 04:00:00', 'America/New_York');

        $this->assertNotNull($instant);
        $this->assertTrue($instant->corrected);
        $this->assertSame('2026-09-14 23:00:00', $instant->startAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 02:00:00', $instant->endAt->format('Y-m-d H:i:s'));
        $this->assertSame('America/New_York', $instant->displayTimezone);
        $this->assertSame('19:00:00', $instant->localStartTime());
        $this->assertSame('2026-09-14', $instant->localDate());
    }

    /** Historical rows keep the stored instant so they still reconcile against the legacy UI. */
    public function testHistoricalColumbusRowKeepsStoredInstant(): void
    {
        $instant = $this->resolve('2018-05-16 15:00:00', null, 'America/New_York');

        $this->assertNotNull($instant);
        $this->assertFalse($instant->corrected);
        $this->assertSame('2018-05-16 15:00:00', $instant->startAt->format('Y-m-d H:i:s'));
        $this->assertSame('America/Denver', $instant->displayTimezone);
        $this->assertNull($instant->endAt);
        $this->assertNull($instant->localEndTime());
    }

    /**
     * The wall clock is identical either side of the cutoff — which is the point: only the absolute
     * instant differs, so the two semantics are invisible in the UI and must be recorded.
     */
    public function testBothSemanticsRenderTheSameWallClock(): void
    {
        $before = $this->resolve('2026-08-20 01:00:00', null, 'America/New_York');
        $after = $this->resolve('2026-08-22 01:00:00', null, 'America/New_York');

        $this->assertSame('19:00:00', $before->localStartTime());
        $this->assertSame('19:00:00', $after->localStartTime());

        $this->assertFalse($before->corrected);
        $this->assertTrue($after->corrected);
        $this->assertNotSame($before->displayTimezone, $after->displayTimezone);
    }

    public function testCutoffBoundaryIsInclusive(): void
    {
        $this->assertTrue($this->resolve(self::CUTOFF, null, 'America/New_York')->corrected);
    }

    public function testSemanticsAreRecordedForLaterReaders(): void
    {
        $corrected = $this->resolve('2026-09-15 01:00:00', null, 'America/New_York');
        $stored = $this->resolve('2018-05-16 15:00:00', null, 'America/New_York');

        $this->assertSame('corrected', $corrected->semanticsMetadata($this->cutoff())['tz_semantics']);
        $this->assertSame('as_stored', $stored->semanticsMetadata($this->cutoff())['tz_semantics']);
        $this->assertSame('America/New_York', $corrected->semanticsMetadata($this->cutoff())['tz_display']);
    }

    public function testMissingStartYieldsNothing(): void
    {
        $this->assertNull($this->resolve(null, '2026-09-15 04:00:00', 'America/Denver'));
        $this->assertNull($this->resolve('  ', null, 'America/Denver'));
    }
}
