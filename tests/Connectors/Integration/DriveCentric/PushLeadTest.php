<?php

declare(strict_types=1);

namespace Kanvas\Tests\Connectors\Integration\DriveCentric;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Handlers\DriveCentricHandler;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class PushLeadTest extends TestCase
{
    public function testPushLeadAction()
    {
        $app = app(Apps::class);
        $people = People::factory()->withContacts(false)
            ->withAppId($app->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create()
            ->getId();

        $region = Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Test Region',
            'currency_id' => 1,
        ]);
        $handler = new DriveCentricHandler(
            $app,
            auth()->user()->getCurrentCompany(),
            $region,
            [
                'base_url' => getenv('TEST_DRIVECENTRIC_BASE_URL'),
                'api_key' => getenv('TEST_DRIVECENTRIC_API_KEY'),
                'api_secret_key' => getenv('TEST_DRIVECENTRIC_API_SECRET_KEY'),
                'store_id' => getenv('TEST_DRIVECENTRIC_STORE_ID')
            ]
        );
        $handler->setup();
        $lead = Lead::factory()
                ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
                ->withPeopleId($people)
                ->create();
        $response = new PushLeadAction($lead)->execute();
        $this->assertNotEmpty($response);
    }
}
