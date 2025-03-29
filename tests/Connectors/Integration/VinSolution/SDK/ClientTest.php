<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution\SDK;

use Kanvas\Apps\Models\Apps;
use Tests\Connectors\Traits\HasVinsolutionConfiguration;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    use HasVinsolutionConfiguration;

    public function testGetAllUsers()
    {
        $app = app(Apps::class);

        $vinClient = $this->getClient($app);

        $token = $vinClient->auth();

        $this->assertIsArray($token);
        $this->assertArrayHasKey('access_token', $token);
        $this->assertArrayHasKey('expires_in', $token);
        $this->assertArrayHasKey('token_type', $token);
    }
}
