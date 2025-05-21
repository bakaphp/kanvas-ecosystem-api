<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Tests\TestCase;

final class PasoRapidoTest extends TestCase
{
    private function getPasoRapidoConfig(): array
    {
        return [
            'client_id' => env('TEST_PASO_RAPIDO_CLIENT_ID'),
            'secret' => env('TEST_PASO_RAPIDO_SECRET'),
        ];
    }

    public function testSetup()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $config = $this->getPasoRapidoConfig();

        $pasoRapidoService = new PasoRapidoService(
            app: $app,
            company: $company,
            config: $config
        );

        $this->assertInstanceOf(PasoRapidoService::class, $pasoRapidoService);
    }

    // public function testVerifyCustomer()
    // {
    //     $app = app(Apps::class);
    //     $company = Companies::first();
    //     $config = $this->getPasoRapidoConfig();

    //     $pasoRapidoService = new PasoRapidoService(
    //         app: $app,
    //         company: $company,
    //         config: $config
    //     );

    //     $result = $pasoRapidoService->verifyCustomer(env('TEST_PASO_RAPIDO_TAG'));
    //     $this->assertTrue($result);
    // }
}
