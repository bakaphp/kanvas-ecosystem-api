<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
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
        $carbon = Carbon::now('America/Los_Angeles');
        $tool = new CompanyWorkHoursTool($lead);
        $result = $tool->execute();
        $hours = explode(' - ', $workHours[$carbon->dayName]);

        $this->assertEquals($carbon->dayName, $result['weekday']);

        $this->assertEquals($hours[0], $result['opens_at_local']);
        $this->assertEquals($hours[1], $result['closes_at_local']);
        $this->assertIsArray($result);
    }
}
