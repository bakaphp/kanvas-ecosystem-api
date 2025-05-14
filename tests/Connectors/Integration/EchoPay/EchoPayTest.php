<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\EchoPay;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardData;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsultServiceQueryData;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetailData;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Tests\TestCase;

final class EchoPayTest extends TestCase
{
    protected function cardData(): array
    {
        return [
            'number' => '4111111111111111',
            'expirationMonth' => '12',
            'expirationYear' => '2030',
            'type' => 'visa',
        ];
    }

    public function getClientData(): array
    {
        return [
            'client_id' => env('TEST_ECHO_PAY_CLIENT_ID'),
            'secret' => env('TEST_ECHO_PAY_SECRET'),
        ];
    }

    public function getMerchantData(): MerchantDetailData
    {
        return MerchantDetailData::from([
            'id' => env('TEST_ECHO_PAY_MERCHANT_ID'),
            'key' => env('TEST_ECHO_PAY_MERCHANT_KEY'),
            'secretKey' => env('TEST_ECHO_PAY_MERCHANT_SECRET'),
        ]);
    }

    public function getService($app, $company)
    {
        return new EchoPayService(
            app: $app,
            company: $company,
            config: $this->getClientData(),
        );
    }

    public function getCardData(): CardData
    {
        return CardData::from([
            'card' => new CardDetailData(
                number: $this->cardData()['number'],
                expirationMonth: $this->cardData()['expirationMonth'],
                expirationYear: $this->cardData()['expirationYear'],
                type: $this->cardData()['type'],
            ),
            'billTo' => new BillingDetailData(
                firstName: 'John',
                lastName: 'Doe',
                address1: '123 Main St',
                city: 'San Francisco',
                administrativeArea: 'CA',
                postalCode: '94105',
                country: 'DO',
                email: 'john@doe.com',
                phone: '8095551234',
            ),
            'merchant' => $this->getMerchantData(),
        ]);
    }

    public function testConsultService()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $echoPayService = $this->getService($app, $company);

        $result = $echoPayService->consultService(ConsultServiceQueryData::from([
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
            $tokenizedCard['instrumentIdentifierId'],
            $this->getMerchantData()
        );

        $this->assertArrayHasKey('serviceCode', $result);
        $this->assertArrayHasKey('contract', $result);
        $this->assertArrayHasKey('merchantKey', $result);
    }
}
