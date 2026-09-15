<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Tests\TestCase;

final class CompanyWorkingHoursTimezoneTest extends TestCase
{
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        // A dedicated company — `work_hours` is a persisted setting, so writing
        // it on the shared auth company would change other tests' outcomes.
        $this->company = Companies::factory()->create();
        $this->company->set('work_hours', [
            'Monday' => '9:00 AM - 5:00 PM',
            'Tuesday' => '9:00 AM - 5:00 PM',
            'Wednesday' => '9:00 AM - 5:00 PM',
            'Thursday' => '9:00 AM - 5:00 PM',
            'Friday' => '9:00 AM - 5:00 PM',
            'Saturday' => 'Closed',
            'Sunday' => 'Closed',
        ]);
    }

    public function testGetTimezoneReturnsNullWhenNotSet(): void
    {
        $this->company->timezone = null;

        $this->assertNull($this->company->getTimezone());
    }

    public function testGetTimezoneReturnsNullForInvalidIanaString(): void
    {
        // Real prod value: America/Indiana without its required city suffix.
        $this->company->timezone = 'America/Indiana';

        $this->assertNull($this->company->getTimezone());
    }

    public function testGetTimezoneReturnsStoredValueWhenValid(): void
    {
        $this->company->timezone = 'America/Santo_Domingo';

        $this->assertSame('America/Santo_Domingo', $this->company->getTimezone());
    }

    public function testIsWithinWorkingHoursWithoutTimezone(): void
    {
        $this->company->timezone = null;

        $this->assertFalse($this->company->isWithinWorkingHours(Carbon::parse('Saturday 12:00 PM', 'America/New_York')));
        $this->assertTrue($this->company->isWithinWorkingHours(Carbon::parse('Monday 12:00 PM', 'America/New_York')));
    }

    public function testIsWithinWorkingHoursWithInvalidTimezone(): void
    {
        $this->company->timezone = 'America/Indiana';

        $this->assertFalse($this->company->isWithinWorkingHours(Carbon::parse('Sunday 12:00 PM', 'America/New_York')));
        $this->assertTrue($this->company->isWithinWorkingHours(Carbon::parse('Monday 12:00 PM', 'America/New_York')));
    }
}
