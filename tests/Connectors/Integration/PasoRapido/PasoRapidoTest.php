<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Client;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Mockery;
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

    public function testVerifyCustomer()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $config = $this->getPasoRapidoConfig();

        $pasoRapidoService = new PasoRapidoService(
            app: $app,
            company: $company,
            config: $config
        );
        $tag = env('TEST_PASO_RAPIDO_TAG');
        $result = $pasoRapidoService->verifyCustomer($tag);
        $this->assertInstanceOf(VerifyCustomerResponse::class, $result);
        $this->assertEquals($tag, $result->device);
    }

    public function testVerifyCustomerWithoutUsername()
    {
        // Create a mock of the HTTP client
        $mockClient = Mockery::mock(Client::class);
        $tag = env('TEST_PASO_RAPIDO_TAG');

        // Set up the expectation
        $mockClient->shouldReceive('post')
            ->once()
            ->with(
                ConfigurationEnum::VERIFY_PATH->value . '?referencia=' . $tag,
                []
            )
            ->andReturn([
                'nombreUsuario' => null,
                'apellidoUsuario' => null,
                'dispositivo' => $tag,
                'descripcionMensaje' => "test description",
                'rnc_Cedula' => "1234",
                'balance' => 2000,
                'tipoDeReferencia' => "test",
                'referencia' => $tag,
                'cuenta' => "1234",
                'estado' => "activo",
            ]);

        // Inject the mock into your class
        $app = app(Apps::class);
        $company = Companies::first();
        $config = $this->getPasoRapidoConfig();

        $pasoRapidoService = new PasoRapidoService(
            app: $app,
            company: $company,
            config: $config,
            client: $mockClient
        );

        // Execute your test
        $result = $pasoRapidoService->verifyCustomer($tag);

        $this->assertInstanceOf(VerifyCustomerResponse::class, $result);
        $this->assertEquals($tag, $result->device);
    }

    public function testConfirmPayment()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $config = $this->getPasoRapidoConfig();

        $pasoRapidoService = new PasoRapidoService(
            app: $app,
            company: $company,
            config: $config
        );
        $tag = env('TEST_PASO_RAPIDO_TAG');
        $transactionId = "747892572499611470" . rand(1000, 9999);
        $customer = $pasoRapidoService->verifyCustomer($tag);
        $confirmedPayment = $pasoRapidoService->confirmPayment(
            PaymentConfirmData::from([
                'reference' => $tag,
                'amount' => 100,
                'fiscalCredit' => false,
                'bankTransaction' => $transactionId,
                'dni' => $customer->document,
            ])
        );

        // $verifiedPayment = $pasoRapidoService->verifyPayment($transactionId);
        // $this->assertInstanceOf(VerifyPaymentResponse::class, $verifiedPayment);
        $this->assertInstanceOf(PaymentConfirmResponse::class, $confirmedPayment);
    }
}
