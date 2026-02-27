<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Tests\TestCase;

class CompanyWorkHoursToolTest extends TestCase
{
    public function testCompanyWorkHoursTool(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set('timezone', 'America/Los_Angeles');
        $workHours = [
            'Monday' => '08:00 - 21:00',
            'Tuesday' => '08:00 - 21:00',
            'Wednesday' => '08:00 - 21:00',
            'Thursday' => '08:00 - 21:00',
            'Friday' => '08:00 - 21:00',
            'Saturday' => '09:00 - 21:00',
            'Sunday' => '09:00 - 21:00',
        ];
        $company->set(ConfigurationEnum::WORKING_HOURS->value, $workHours);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Freeze time to a specific date and time (e.g., Wednesday at 12:00 PM)
        $fixedNow = Carbon::create(
            2026,
            1,
            7,
            12,
            0,
            0,
            'America/Los_Angeles'
        ); // Wednesday
        Carbon::setTestNow($fixedNow);
        $tool = new CompanyWorkHoursTool($lead);
        $result = $tool->execute();
        $hours = explode(' - ', $workHours['Wednesday']);

        $this->assertEquals('Wednesday', $result['weekday']);

        $this->assertEquals($hours[0], $result['opens_at_local']);
        $this->assertEquals($hours[1], $result['closes_at_local']);
        $this->assertIsArray($result);

        Carbon::setTestNow(); // Reset frozen time
    }
}
