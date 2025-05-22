<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyCustomerResponse;
use Kanvas\Connectors\PasoRapido\DataTransferObject\VerifyPaymentResponse;
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
        $transactionId = "7478925724996114704" . rand(100, 999);
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
