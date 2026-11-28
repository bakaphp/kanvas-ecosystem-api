<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Session\SessionClient;
use Kanvas\Connectors\VinSolution\Session\SessionTokenService;
use Kanvas\Exceptions\ValidationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Tests\TestCase;

final class SessionTokenServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const CACHE_KEY = 'vinSolutionSessionToken-7-9';

    public function testReturnsTheCachedTokenWithoutCallingAuth(): void
    {
        Redis::shouldReceive('get')->once()->with(self::CACHE_KEY)->andReturn('cached-jwt');

        $client = Mockery::mock(SessionClient::class);
        $client->shouldNotReceive('getToken');

        $service = $this->service($this->company(cookies: 'c=1'), $client);

        $this->assertSame('cached-jwt', $service->getAccessToken());
    }

    public function testMintsFromTheStoredCookiesAndCachesUnderTheTokenLife(): void
    {
        Redis::shouldReceive('get')->once()->with(self::CACHE_KEY)->andReturn(null);
        Redis::shouldReceive('set')
            ->once()
            ->with(
                self::CACHE_KEY,
                'fresh-jwt',
                'EX',
                3000
            );

        $client = Mockery::mock(SessionClient::class);
        $client->shouldReceive('getToken')
            ->once()
            ->with('c=1')
            ->andReturn(['accessToken' => 'fresh-jwt', 'expiresIn' => '3599', 'selectedILMUserID' => '1']);

        $service = $this->service($this->company(cookies: 'c=1'), $client);

        $this->assertSame('fresh-jwt', $service->getAccessToken());
    }

    public function testThrowsWhenTheCompanyHasNoCookiesStored(): void
    {
        Redis::shouldReceive('get')->once()->with(self::CACHE_KEY)->andReturn(null);

        $client = Mockery::mock(SessionClient::class);
        $client->shouldNotReceive('getToken');

        $this->expectException(ValidationException::class);
        $this->service($this->company(cookies: null), $client)->getAccessToken();
    }

    private function service(Companies $company, SessionClient $client): SessionTokenService
    {
        $app = Mockery::mock(Apps::class);
        $app->shouldReceive('getId')->andReturn(7);

        return new SessionTokenService($app, $company, $client);
    }

    private function company(?string $cookies): MockInterface&Companies
    {
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('getId')->andReturn(9);
        $company->shouldReceive('get')->with(ConfigurationEnum::SESSION_COOKIES->value)->andReturn($cookies);

        return $company;
    }
}
