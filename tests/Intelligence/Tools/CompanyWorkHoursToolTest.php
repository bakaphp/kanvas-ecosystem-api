<?php
declare(strict_types=1);
namespace Kanvas\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kiwilan\XmlReader\XmlReader;
use Tests\TestCase;
use Kanvas\Companies\Enums\ConfigurationEnum;

class CompanyWorkHoursToolTest extends TestCase
{
    public function testCompanyWorkHoursTool(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set('timezone', 'America/Los_Angeles');
        $company->set(ConfigurationEnum::WORKING_HOURS->value, [
            "Monday"=> "08:00 - 21:00",
            "Tuesday"=> "08:00 - 21:00",
            "Wednesday"=> "08:00 - 21:00",
            "Thursday"=> "08:00 - 21:00",
            "Friday"=> "08:00 - 21:00",
            "Saturday"=> "09:00 - 21:00",
            "Sunday"=> "09:00 - 21:00"
        ]);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $tool = new CompanyWorkHoursTool($lead);
        $result = $tool->execute();
        $this->assertIsArray($result);
    }
}
