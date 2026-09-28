<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as CorporateField;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;

trait LoadsParkingApplicationFixture
{
    protected function parkingApplication(Apps $app, Companies $company, array $fields): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['title' => 'Parqueo Plaza Central']);

        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $lead->set($key, $value);
            }
        }

        CorporateField::COMPANY_ID->writeTo($lead, (string) $company->getId());

        return $lead;
    }

    protected function fixtureFields(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/Fixtures/parking_application_custom_fields.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
