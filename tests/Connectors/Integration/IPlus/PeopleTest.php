<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\IPlus;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Actions\SavePeopleToIPlusAction;
use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PeopleTest extends TestCase
{
    public function testCreatePeople()
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::AUTH_BASE_URL->value, getenv('TEST_IPLUS_BASE_URL'));
        $app->set(ConfigurationEnum::CLIENT_ID->value, getenv('TEST_IPLUS_CLIENT_ID'));
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, getenv('TEST_IPLUS_CLIENT_SECRET'));

        $people = People::fromApp($app)->first();
        $people->company->set(ConfigurationEnum::COMPANY_ID->value, '01');

        $savePeopleToIplusAction = new SavePeopleToIPlusAction($people);
        $this->assertNotEmpty($savePeopleToIplusAction->execute());
    }
}
