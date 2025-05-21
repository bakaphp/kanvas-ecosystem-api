<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\EchoPay;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsultServiceQuery;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthenticationInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\DeviceInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\OrderInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentResponse;

final class EchoPayTest extends EchoPayBase
{
    private function getSetupData($app, $company)
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        $tokenizedCard = $echoPayService->addCard($this->getCardData());

        $setupResult = $echoPayService->setupPayer(
            "TC50171_3",
            $tokenizedCard['paymentInstrumentId'],
            MerchantDetail::from($this->getMerchantData())
        );

        return [
            'tokenizedCard' => $tokenizedCard,
            'referenceId' => $setupResult['consumerAuthenticationInformation']['referenceId'],
        ];
    }
    public function testConsultService()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        $result = $echoPayService->consultService(ConsultServiceQuery::from([
            'merchantKey' => '00000000016739100006575',
            'serviceCode' => '0101',
            'contract' => '6537824'
        ]));

        $expectedKeys = [
            'serviceCode',
            'contractNumber',
            'invoiceNumber',
            'invoiceDate',
            'clientName',
            'currency',
            'amount',
            'maxAmount',
            'minAmount',
            'chargeId',
            'validatorId',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testAddCard()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        $result = $echoPayService->addCard($this->getCardData());
        $this->assertArrayHasKey('cardNumber', $result);
        $this->assertArrayHasKey('expirationDate', $result);
        $this->assertArrayHasKey('instrumentIdentifierId', $result);
        $this->assertArrayHasKey('paymentInstrumentId', $result);
    }

    public function testSetupPayer()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        $tokenizedCard = $echoPayService->addCard($this->getCardData());

        $result = $echoPayService->setupPayer(
            "TC50171_3",
            $tokenizedCard['paymentInstrumentId'],
            MerchantDetail::from($this->getMerchantData())
        );

        $this->assertArrayHasKey('clientReferenceInformation', $result);
        $this->assertArrayHasKey('consumerAuthenticationInformation', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('submitTimeUtc', $result);
    }

    public function testPayerEnrollment()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        ["tokenizedCard" => $tokenizedCard, "referenceId" => $referenceId] = $this->getSetupData($app, $company);

        $result = $echoPayService->checkPayerEnrollment(
            PaymentDetail::from([
                'orderCode' => 'TC50171_3',
                'paymentInstrumentId' => $tokenizedCard['paymentInstrumentId'],
                'orderInformation' => OrderInformation::from([
                    'currency' => 'DOP',
                    'totalAmount' => '100',
                    'billTo' => $this->getCardData()->billTo,
                ]),
                'deviceInformation' => DeviceInformation::from([
                    "httpAcceptContent" => "application/json",
                    "httpBrowserLanguage" => "en_us",
                    "userAgentBrowserValue" => "chrome"
                ]),
                'consumerAuthenticationInformation' => ConsumerAuthenticationInformation::from([
                    "deviceChannel" => "BROWSER",
                    "returnUrl" => "http://localhost:3000/return-url.js",
                    "referenceId" => $referenceId,
                    "transactionMode" => "eCommerce"

                ]),
            ]),
            $this->getCardData()->merchant,
        );

        $this->assertArrayHasKey('clientReferenceInformation', $result);
        $this->assertArrayHasKey('consumerAuthenticationInformation', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('submitTimeUtc', $result);
    }

    public function testValidatePayerAuthResult()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        $transactionId = env('TEST_ECHO_PAY_TRANSACTION_ID');
        $tokenizedCard = $echoPayService->addCard($this->getCardData());

        $result = $echoPayService->validatePayerAuthResult(
            $transactionId,
            PaymentDetail::from([
                'orderCode' => 'TC50171_3',
                'paymentInstrumentId' => $tokenizedCard['paymentInstrumentId'],
                'orderInformation' => OrderInformation::from([
                    'currency' => 'DOP',
                    'totalAmount' => '100',
                ]),
            ]),
            $this->getCardData()->merchant,
        );

        $this->assertArrayHasKey('consumerAuthenticationInformation', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertInstanceOf(ConsumerAuthentication::class, $result['consumerAuthenticationInformation']);
        $this->assertEquals("AUTHENTICATION_SUCCESSFUL", $result['status']);
    }

    public function testPayService()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        ["tokenizedCard" => $tokenizedCard, "referenceId" => $referenceId] = $this->getSetupData($app, $company);

        $cardData = $this->getCardData();

        $result = $echoPayService->payService(
            PaymentDetail::from([
                'orderCode' => 'TC50171_3',
                'paymentInstrumentId' => $tokenizedCard['paymentInstrumentId'],
                'orderInformation' => OrderInformation::from([
                    'currency' => 'DOP',
                    'totalAmount' => '100',
                    'billTo' => $cardData->billTo,
                ]),
                'deviceInformation' => DeviceInformation::from([
                    "httpAcceptContent" => "application/json",
                    "httpBrowserLanguage" => "en_us",
                    "userAgentBrowserValue" => "chrome"
                ]),
                'consumerAuthenticationInformation' => ConsumerAuthenticationInformation::from([
                    "deviceChannel" => "BROWSER",
                    "returnUrl" => "http://localhost:3000/portal/accept-code",
                    "referenceId" => $referenceId,
                    "transactionMode" => "eCommerce"

                ]),
            ]),
            ConsumerAuthentication::from([
                "indicator" => "vbv",
                "eciRaw" => "05",
                "authenticationResult" => "0",
                "strongAuthentication" => [
                    "OutageExemptionIndicator" => "0"
                ],
                "authenticationStatusMsg" => "Success",
                "eci" => "05",
                "token" => "AxjzbwSTlSvEI+byinVHAKUBTyD9dO6A1h04goIQyaSZejFcRGKBWAAAXBJS",
                "cavv" => "AAIBBYNoEwAAACcKhAJkdQAAAAA=",
                "paresStatus" => "Y",
                "xid" => "AAIBBYNoEwAAACcKhAJkdQAAAAA=",
                "directoryServerTransactionId" => "cd346fc0-d248-48f7-9b76-1f4741076fec",
                "threeDSServerTransactionId" => "3bf3718f-39d0-42eb-acda-ced2f80fc6a6",
                "specificationVersion" => "2.2.0",
                "acsTransactionId" => "27442f28-623b-4115-ad48-6ede081db03c"
            ]),
            $cardData->merchant,
            [
                "merchantKey" => "00000000016739100006575",
                "channelCode" => "004",
                "serviceCode" => "0101",
                "serviceTypeId" => "106",
                "contract" => "6537824"
            ]
        );

        $this->assertInstanceOf(PaymentResponse::class, $result);
        $this->assertEquals('PAYED', $result->status->name);
    }
}
