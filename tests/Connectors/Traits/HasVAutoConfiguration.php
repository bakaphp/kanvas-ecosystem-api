<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VAuto\Enums\ConfigurationEnum;
use Mockery;
use SoapClient;

trait HasVAutoConfiguration
{
    protected const string VAUTO_ACCESS_ID = 'access-1';
    protected const string VAUTO_SECRET_KEY = 'secret-1';
    protected const string VAUTO_ENTITY_ID = 'dealer-42';

    /**
     * A fresh company per test: company settings live in Redis and the `ecosystem` connection, so
     * configuring the shared auth company would leak into every parallel test process.
     */
    protected function vAutoCompany(): Companies
    {
        $company = Companies::factory()->create();
        $this->configureVAuto($company);

        return $company;
    }

    protected function configureVAuto(CompanyInterface $company): void
    {
        $company->set(ConfigurationEnum::ACCESS_ID->value, self::VAUTO_ACCESS_ID);
        $company->set(ConfigurationEnum::SECRET_KEY->value, self::VAUTO_SECRET_KEY);
        $company->set(ConfigurationEnum::ENTITY_ID->value, self::VAUTO_ENTITY_ID);
    }

    /**
     * @param callable(array): bool|null $capture receives the `__soapCall` arguments; return true to accept
     */
    protected function soapClientReturning(object $response, ?callable $capture = null): SoapClient
    {
        $soap = Mockery::mock(SoapClient::class);
        $soap->shouldReceive('__setSoapHeaders')->once()->andReturnTrue();
        $soap->shouldReceive('__soapCall')
            ->once()
            ->with('CreateAppraisal', $capture === null ? Mockery::any() : Mockery::on($capture))
            ->andReturn($response);

        return $soap;
    }

    protected function soapClientNeverCalled(): SoapClient
    {
        $soap = Mockery::mock(SoapClient::class);
        $soap->shouldNotReceive('__soapCall');

        return $soap;
    }

    protected function appraisalResponse(int|string $appraisalId, string $deepLink = ''): object
    {
        return (object) [
            'CreateAppraisalResult' => (object) [
                'AppraisalId' => $appraisalId,
                'DeepLink' => $deepLink,
            ],
        ];
    }
}
