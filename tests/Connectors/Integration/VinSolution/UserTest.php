<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Tests\TestCase;

final class UserTest extends TestCase
{
    public function testGetAllUsers()
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CLIENT_ID->value, getenv('TEST_VINSOLUTIONS_CLIENT_ID'));
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, getenv('TEST_VINSOLUTIONS_CLIENT_SECRET'));
        $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_VINSOLUTIONS_API_KEY'));
        $app->set(ConfigurationEnum::API_KEY_DIGITAL_SHOWROOM->value, getenv('TEST_VINSOLUTIONS_API_KEY_DIGITAL_SHOWROOM'));

        $company = Companies::first();
        $company->set(ConfigurationEnum::COMPANY->value, getenv('TEST_VINSOLUTIONS_COMPANY_ID'));

        $dealer = Dealer::getById($company->get(ConfigurationEnum::COMPANY->value));
        $vinUsers = $dealer->getUsers($dealer);

        $this->assertTrue(count($vinUsers) > 0);
    }
}
