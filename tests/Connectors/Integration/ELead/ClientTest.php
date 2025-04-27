<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Kanvas\Apps\Models\Apps;
use Tests\Connectors\Traits\HasELeadConfiguration;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    use HasELeadConfiguration;

    public function testAuth()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $eleadClient = $this->getClient($app, $company);

        $token = $eleadClient->auth();
        
        $this->assertArrayHasKey('access_token', $token);
        $this->assertArrayHasKey('expires_in', $token);
        $this->assertArrayHasKey('token_type', $token);
        $this->assertArrayHasKey('scope', $token);
    }
}
