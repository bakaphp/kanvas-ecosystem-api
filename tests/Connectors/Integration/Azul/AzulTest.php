<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\Client;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentRequest;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentResponse;
use Kanvas\Connectors\Azul\Enums\TransactionTypeEnum;
use Kanvas\Exceptions\ValidationException;

final class AzulTest extends AzulBase
{
    public function testSaleWithCard(): void
    {
        $service  = $this->getService();
        $request  = $this->buildSaleRequest();
        $response = $service->sale($request);

        $this->assertInstanceOf(AzulPaymentResponse::class, $response);
        $this->assertTrue($response->isApproved(), 'Expected IsoCode 00, got: ' . $response->isoCode);
        $this->assertNotEmpty($response->azulOrderId);
        $this->assertEquals('APROBADA', $response->responseMessage);
    }

    public function testSaleWithDataVault(): void
    {
        $service = $this->getService();

        // First sale: save the card to DataVault
        $firstRequest  = $this->buildSaleRequest(['saveToDataVault' => '1']);
        $firstResponse = $service->sale($firstRequest);

        $this->assertTrue($firstResponse->isApproved());
        $this->assertNotEmpty($firstResponse->dataVaultToken, 'Expected DataVaultToken in response');

        // Second sale: use the DataVault token (no raw card fields)
        $credentials     = $this->getCredentials();
        $tokenOnlyRequest = new AzulPaymentRequest(
            channel: $credentials['channel'],
            store: $credentials['store'],
            trxType: TransactionTypeEnum::SALE,
            amount: '100',
            itbis: '18',
            customOrderId: 'TEST-TOKEN-' . time(),
            dataVaultToken: $firstResponse->dataVaultToken,
            forceNo3DS: '1',
        );

        $tokenResponse = $service->sale($tokenOnlyRequest);
        $this->assertTrue($tokenResponse->isApproved());
        $this->assertNotEmpty($tokenResponse->azulOrderId);
    }

    public function testHold(): void
    {
        $service  = $this->getService();
        $request  = $this->buildSaleRequest();
        $response = $service->hold($request);

        $this->assertInstanceOf(AzulPaymentResponse::class, $response);
        $this->assertTrue($response->isApproved(), 'Hold failed: ' . $response->responseMessage);
        $this->assertNotEmpty($response->azulOrderId);
    }

    public function testRefund(): void
    {
        $service = $this->getService();

        // First do a sale to get an AzulOrderId to refund
        $saleResponse = $service->sale($this->buildSaleRequest());
        $this->assertTrue($saleResponse->isApproved());

        $refundResponse = $service->refund(
            azulOrderId: $saleResponse->azulOrderId,
            amount: '100',
            itbis: '18',
            customOrderId: 'REFUND-' . time(),
        );

        $this->assertInstanceOf(AzulPaymentResponse::class, $refundResponse);
        $this->assertTrue($refundResponse->isApproved(), 'Refund failed: ' . $refundResponse->responseMessage);
    }

    public function testInvalidCredentialsThrowsException(): void
    {
        $this->expectException(ValidationException::class);

        $app     = app(Apps::class);
        $company = Companies::first();

        new Client($app, $company, [
            'auth1' => '',
            'auth2' => '',
        ]);
    }
}
