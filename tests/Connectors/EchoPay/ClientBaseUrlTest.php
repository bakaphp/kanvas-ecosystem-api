<?php

declare(strict_types=1);

namespace Tests\Connectors\EchoPay;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Client;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Override;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class ClientBaseUrlTest extends TestCase
{
    public function testTheAppSettingWinsOverTheDefault(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::BASE_URL->value, 'https://custom.example.test');
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->assertSame('https://custom.example.test', $this->baseUrlFor($app));
        } finally {
            $app->del(ConfigurationEnum::BASE_URL->value);
        }
    }

    public function testProductionFallsBackToTheLiveGateway(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::BASE_URL->value);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame('https://apiv2.portalnet.com.do', $this->baseUrlFor($app));
    }

    public function testOtherEnvironmentsFallBackToTheSandbox(): void
    {
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::BASE_URL->value);

        $this->assertSame(ConfigurationEnum::SANDBOX_URL->value, $this->baseUrlFor($app));
    }

    private function baseUrlFor(Apps $app): string
    {
        $company = Auth::user()->getCurrentCompany();
        $credentials = [
            'client_id' => 'id',
            'secret' => 'secret',
        ];

        $client = new class ($app, $company, $credentials) extends Client {
            #[Override]
            public function getAccessToken(): string
            {
                return 'token';
            }

            public function baseUrl(): string
            {
                return $this->baseUrl;
            }
        };

        return $client->baseUrl();
    }
}
