<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution\SDK;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Tests\Connectors\Traits\HasVinsolutionConfiguration;
use Tests\TestCase;

final class UserTest extends TestCase
{
    use HasVinsolutionConfiguration;

    public function testGetAllUsers()
    {
        $app = app(Apps::class);
        $vinClient = $this->getClient($app);

        $dealer = Dealer::getById($vinClient->dealerId, $app);
        $vinUsers = Dealer::getUsers($dealer, $app);

        $this->assertTrue(count($vinUsers) > 0);
    }
}
