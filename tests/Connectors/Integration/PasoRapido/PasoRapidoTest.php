<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Tests\TestCase;

final class PasoRapidoTest extends TestCase
{
    public function testSetup()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $pasoRapidoService = new PasoRapidoService(
            app: $app,
            company: $company,
            config: [
                'client_id' => getenv('TEST_PASO_RAPIDO_CLIENT_ID'),
                'secret' => getenv('TEST_PASO_RAPIDO_SECRET'),
            ]
        );

        $result = $pasoRapidoService->verifyCustomer('1234567890');
        print_r($result);
        $this->assertTrue($result);
    }
}
