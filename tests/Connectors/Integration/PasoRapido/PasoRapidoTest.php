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
        $config = [
            'client_id' => env('TEST_PASO_RAPIDO_CLIENT_ID'),
            'secret' => env('TEST_PASO_RAPIDO_SECRET'),
        ];

        $pasoRapidoService = new PasoRapidoService(
            app: $app,
            company: $company,
            config: $config
        );

        $tag = env('TEST_PASO_RAPIDO_TAG');
        $result = $pasoRapidoService->verifyCustomer($tag);
        print_r($result);
        $this->assertTrue($result);
    }
}
