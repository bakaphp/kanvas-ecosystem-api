<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OfferLogix;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OfferLogix\Actions\SoftPullAction;
use Kanvas\Connectors\OfferLogix\DataTransferObject\SoftPull;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class SoftPullTest extends TestCase
{
    public function testSoftPull(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(EnumsConfigurationEnum::COMPANY_SOURCE_ID->value, getenv('TEST_OFFER_LOGIX_SOURCE_ID'));

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $softPullAction = new SoftPullAction($lead, $lead->people);
        $result = $softPullAction->execute(new SoftPull(
            $lead->people->firstname,
            $lead->people->lastname,
            '4444',
            'Atlanta',
            'GA',
            '30308',
        ));

        $this->assertNotNull($result);
        $this->assertTrue(filter_var($result, FILTER_VALIDATE_URL) !== false);
    }
}
