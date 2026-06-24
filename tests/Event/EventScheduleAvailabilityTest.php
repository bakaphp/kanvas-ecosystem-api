<?php

declare(strict_types=1);

namespace Tests\Event;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Repositories\EventScheduleRepository;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Guards the "owner with an empty calendar shows 0 free slots" bug: when nothing is
 * booked the generator fills the whole work day, so 0 slots could only mean the work
 * hours failed to resolve. resolveDayHours must read the real config shape (capitalized
 * weekly-map keys) and getAvailableSlotsForUser must honor WORKING_DAYS.
 */
class EventScheduleAvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    public function testCapitalizedWeeklyMapYieldsSlotsForEmptyCalendarAndHonorsWorkingDays(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        // Fresh user → no events in the window, so any 0 result is a parse failure.
        $owner = Users::factory()->create();

        // 2026-06-15 is a Monday; window spans Mon-Sun.
        $from = Carbon::parse('2026-06-15 00:00', 'America/New_York');
        $to = $from->copy()->addDays(6)->endOfDay();

        // Capitalized keys — the real config shape the canonical work-hours tool emits.
        // Saturday/Sunday have hours here, but WORKING_DAYS must exclude them.
        $workingHours = [
            'Monday' => '09:00-18:00',
            'Tuesday' => '09:00-18:00',
            'Wednesday' => '09:00-18:00',
            'Thursday' => '09:00-18:00',
            'Friday' => '09:00-18:00',
            'Saturday' => '09:00-18:00',
            'Sunday' => '09:00-18:00',
        ];

        $slots = new EventScheduleRepository()->getAvailableSlotsForUser(
            app: $app,
            company: $company,
            user: $owner,
            from: $from,
            to: $to,
            durationMinutes: 30,
            workingHours: $workingHours,
            limit: 100,
            workingDays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        );

        $this->assertNotEmpty($slots, 'Empty calendar with valid work hours must yield free slots');

        foreach ($slots as $slot) {
            $dayOfWeek = Carbon::parse($slot['start'])->dayOfWeek;
            $this->assertNotContains(
                $dayOfWeek,
                [Carbon::SATURDAY, Carbon::SUNDAY],
                'WORKING_DAYS must exclude weekend slots',
            );
        }
    }

    public function testUnreadableWorkHoursStillReturnsZeroRatherThanCrash(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $owner = Users::factory()->create();

        $from = Carbon::parse('2026-06-15 00:00', 'America/New_York');
        $to = $from->copy()->addDays(3)->endOfDay();

        // No recognizable day keys and no opens/closes → genuinely no window.
        $slots = new EventScheduleRepository()->getAvailableSlotsForUser(
            app: $app,
            company: $company,
            user: $owner,
            from: $from,
            to: $to,
            durationMinutes: 30,
            workingHours: ['foo' => 'bar'],
            limit: 10,
        );

        $this->assertSame([], $slots);
    }
}
