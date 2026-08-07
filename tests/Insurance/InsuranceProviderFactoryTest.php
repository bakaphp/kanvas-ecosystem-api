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
