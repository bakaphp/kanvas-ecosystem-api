<?php

declare(strict_types=1);

namespace Tests\Connectors\UniversalSeguros;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\UniversalSeguros\Client;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\EnvironmentEnum;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Universal's QA host chains to a GoDaddy root Mozilla doesn't ship yet, so QA-only
 * setups need to turn peer verification off. Verification must stay on everywhere
 * else, including when the flag is missing or garbage.
 */
class ClientSslVerificationTest extends TestCase
{
    public function testVerificationIsOnWhenTheFlagIsNotSet(): void
    {
        $this->assertTrue($this->clientWith(null)->verifiesSsl());
    }

    public function testFalsyFlagValuesTurnVerificationOff(): void
    {
        foreach (['0', 'false', 'off', 'no', false, 0] as $flag) {
            $this->assertFalse(
                $this->clientWith($flag)->verifiesSsl(),
                var_export($flag, true) . ' should disable peer verification'
            );
        }
    }

    public function testTruthyFlagValuesKeepVerificationOn(): void
    {
        foreach (['1', 'true', 'on', 'yes', true, 1] as $flag) {
            $this->assertTrue(
                $this->clientWith($flag)->verifiesSsl(),
                var_export($flag, true) . ' should keep peer verification on'
            );
        }
    }

    public function testUnreadableFlagValuesFailClosedIntoVerification(): void
    {
        foreach (['', 'maybe', 'null', []] as $flag) {
            $this->assertTrue(
                $this->clientWith($flag)->verifiesSsl(),
                var_export($flag, true) . ' should fall back to peer verification'
            );
        }
    }

    public function testTheFlagReachesGuzzleAndNotJustOurGetter(): void
    {
        $this->assertFalse($this->guzzleVerifyOption($this->clientWith('0')));
        $this->assertTrue($this->guzzleVerifyOption($this->clientWith(null)));
    }

    private function clientWith(mixed $verifySslFlag): Client
    {
        $app = Mockery::mock(AppInterface::class);
        $app->shouldReceive('getId')->andReturn(1);

        $config = [
            ConfigurationEnum::ENVIRONMENT->value => EnvironmentEnum::QA->value,
            ConfigurationEnum::CLIENT_ID->value => 'client-id',
            ConfigurationEnum::CLIENT_SECRET->value => 'client-secret',
            ConfigurationEnum::SCOPES->value => ConfigurationEnum::defaultScopes(),
            ConfigurationEnum::VERIFY_SSL->value => $verifySslFlag,
        ];

        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('getId')->andReturn(2);
        $company->shouldReceive('get')->andReturnUsing(fn (string $key) => $config[$key] ?? null);

        return new Client($app, $company);
    }

    private function guzzleVerifyOption(Client $client): mixed
    {
        $guzzleProperty = new ReflectionProperty(Client::class, 'client');
        /** @var GuzzleClient $guzzle */
        $guzzle = $guzzleProperty->getValue($client);

        $configProperty = new ReflectionProperty(GuzzleClient::class, 'config');

        return $configProperty->getValue($guzzle)['verify'] ?? null;
    }
}
