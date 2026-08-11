<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Souk\Orders\Models\Order;
use Mockery;
use Tests\TestCase;

class InsuranceProviderFactoryTest extends TestCase
{
    public function testUnknownProviderFailsLoudlyInsteadOfSilentlyDoingNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("No insurance provider registered for 'aseguradora-fantasma'");

        InsuranceProviderFactory::make(
            'aseguradora-fantasma',
            app(Apps::class),
            Mockery::mock(CompanyInterface::class)
        );
    }

    public function testEveryRegisteredProviderIsBoundInTheContainer(): void
    {
        $this->assertTrue(app()->bound('insurance_provider.' . UniversalSegurosProvider::NAME));
    }

    public function testAnOrderThatWasNeverQuotedHasNoProviderToResolve(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::PROVIDER->value)
            ->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quote it first');

        InsuranceProviderFactory::forOrder($order);
    }

    /**
     * The acting company is the client browsing quotes; it has no contract with the
     * insurer and must never be asked for credentials.
     */
    public function testQuotingReadsCredentialsFromThePlatformNotFromWhoeverIsActing(): void
    {
        $platform = auth()->user()->getCurrentCompany();

        $app = Mockery::mock(Apps::class);
        $app->shouldReceive('get')->with('B2B_MAIN_COMPANY_ID')->andReturn($platform->getId());

        $client = Mockery::mock(CompanyInterface::class);
        $client->shouldReceive('getId')->andReturn((int) $platform->getId() + 1);
        $client->shouldNotReceive('get');

        $this->assertSame(
            (int) $platform->getId(),
            (int) InsuranceProviderFactory::credentialCompany($app, $client)->getId()
        );
    }

    public function testWithoutAPlatformDeclaredTheActingCompanyKeepsItsOwnCredentials(): void
    {
        $app = Mockery::mock(Apps::class);
        $app->shouldReceive('get')->with('B2B_MAIN_COMPANY_ID')->andReturn(null);

        $acting = Mockery::mock(CompanyInterface::class);
        $acting->shouldReceive('getId')->andReturn(99);

        $this->assertSame($acting, InsuranceProviderFactory::credentialCompany($app, $acting));
    }

    public function testThePlatformActingOnItsOwnBehalfIsNotLookedUpAgain(): void
    {
        $app = Mockery::mock(Apps::class);
        $app->shouldReceive('get')->with('B2B_MAIN_COMPANY_ID')->andReturn(1272);

        $platform = Mockery::mock(CompanyInterface::class);
        $platform->shouldReceive('getId')->andReturn(1272);

        $this->assertSame($platform, InsuranceProviderFactory::credentialCompany($app, $platform));
    }

    public function testAPlatformKeyPointingAtNothingSaysSoInsteadOfFailingDeeper(): void
    {
        $app = Mockery::mock(Apps::class);
        $app->shouldReceive('get')->with('B2B_MAIN_COMPANY_ID')->andReturn(987654321);

        $acting = Mockery::mock(CompanyInterface::class);
        $acting->shouldReceive('getId')->andReturn(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        InsuranceProviderFactory::credentialCompany($app, $acting);
    }

    public function testExplicitProviderChoiceWinsOverTheCompanyDefault(): void
    {
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldNotReceive('get');

        $this->assertSame(
            'universal_seguros',
            InsuranceProviderFactory::resolveName($company, 'universal_seguros')
        );
    }

    public function testWithoutAnExplicitChoiceTheCompanyDefaultIsUsed(): void
    {
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::PROVIDER->value)
            ->andReturn('universal_seguros');

        $this->assertSame('universal_seguros', InsuranceProviderFactory::resolveName($company));
    }

    public function testNoChoiceAndNoCompanyDefaultIsAnError(): void
    {
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('get')
            ->with(InsuranceCustomFieldEnum::PROVIDER->value)
            ->andReturn(null);

        $this->expectException(InvalidArgumentException::class);

        InsuranceProviderFactory::resolveName($company);
    }
}
