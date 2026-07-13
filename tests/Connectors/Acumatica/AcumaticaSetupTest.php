<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\DataTransferObject\Acumatica as AcumaticaDto;
use Kanvas\Connectors\Acumatica\Services\AcumaticaService;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

class AcumaticaSetupTest extends TestCase
{
    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company()
    {
        return auth()->user()->getCurrentCompany();
    }

    public function testFromMultipleMapsInputFields(): void
    {
        $dto = AcumaticaDto::from(
            [
                'base_url' => 'https://erp.example.test/AcumaticaERP',
                'username' => 'svc-integration',
                'password' => 'secret',
                'company' => 'ACME',
                'endpoint_name' => 'Default',
                'endpoint_version' => '24.200.001',
                'branch' => 'MAIN',
                'sql_host' => '10.0.0.5',
                'sql_port' => '1433',
                'sql_database' => 'AcumaticaReplica',
                'sql_username' => 'reader',
                'sql_password' => 'ro-secret',
            ],
            $this->app(),
            $this->company(),
        );

        $this->assertSame('https://erp.example.test/AcumaticaERP', $dto->baseUrl);
        $this->assertSame('svc-integration', $dto->username);
        $this->assertSame('ACME', $dto->acumaticaCompany);
        $this->assertSame('Default', $dto->endpointName);
        $this->assertSame('24.200.001', $dto->endpointVersion);
        $this->assertSame(1433, $dto->sqlPort);
        $this->assertSame('AcumaticaReplica', $dto->sqlDatabase);

        $config = $dto->toConfig();
        $this->assertSame('svc-integration', $config['username']);
        $this->assertSame('10.0.0.5', $config['sqlHost']);
        $this->assertArrayHasKey('sqlPassword', $config);
    }

    public function testFromMultipleAppliesEndpointDefaults(): void
    {
        $dto = AcumaticaDto::from(
            [
                'base_url' => 'https://erp.example.test/AcumaticaERP',
                'username' => 'svc-integration',
                'password' => 'secret',
                'company' => 'ACME',
            ],
            $this->app(),
            $this->company(),
        );

        $this->assertSame('', $dto->endpointName);
        $this->assertSame('23.200.001', $dto->endpointVersion);
        $this->assertNull($dto->sqlHost);
        $this->assertSame(1433, $dto->sqlPort);
    }

    public function testSetupThrowsWhenRequiredConfigMissing(): void
    {
        $dto = AcumaticaDto::from(
            ['base_url' => 'https://erp.example.test/AcumaticaERP'],
            $this->app(),
            $this->company(),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Acumatica configuration is missing');

        AcumaticaService::setup($dto);
    }
}
