<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Tools\HolidaysMonthTool;
use Tests\TestCase;

class HolidaysMonthToolTest extends TestCase
{
    public function testHolidaysMonth()
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $tool = new HolidaysMonthTool($lead);
        $result = $tool->execute();
        dump($result);
    }
}
