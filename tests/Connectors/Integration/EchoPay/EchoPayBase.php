<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\EchoPay;

use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardTokenizationData;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDefinedInformationData;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetailData;
use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Tests\TestCase;

class EchoPayBase extends TestCase
{
    protected function cardData(): array
    {
        return [
            'number' => '4456530000001096',
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

    public function getMerchantData(): array
    {
        return [
            'id' => env('TEST_ECHO_PAY_MERCHANT_ID'),
            'key' => env('TEST_ECHO_PAY_MERCHANT_KEY'),
            'secretKey' => env('TEST_ECHO_PAY_MERCHANT_SECRET'),

        ];
    }

    public function getService($app, $company)
    {
        return new EchoPayService(
            app: $app,
            company: $company,
            config: $this->getClientData(),
        );
    }

    public function getCardData(): CardTokenizationData
    {
        return CardTokenizationData::from([
            'card' => new CardDetailData(
                number: $this->cardData()['number'],
                expirationMonth: $this->cardData()['expirationMonth'],
                expirationYear: $this->cardData()['expirationYear'],
                type: $this->cardData()['type'],
            ),
            'billTo' => new BillingDetailData(
                firstName: "Juan",
                lastName: "Pérez",
                address1: "Calle Duarte #45",
                city: "Santo Domingo",
                administrativeArea: "Distrito Nacional",
                postalCode: "10101",
                country: "DO",
                email: "juan.perez@example.com",
                phone: "8095551234"
            ),
            'merchant' => new MerchantDetailData(
                id: $this->getMerchantData()['id'],
                key: $this->getMerchantData()['key'],
                secretKey: $this->getMerchantData()['secretKey'],
                merchantDefinedInformation: new MerchantDefinedInformationData(
                    category: MerchantCategoryEnum::RETAIL,
                    cardIdentifier: 'visanetdr_00000000000000',
                    platform: MerchantPlatformEnum::WEB,
                    customerId: 'user_123',
                    tokenization: MerchantTokenizationEnum::TOKENIZATION_YES,
                    documentType: MerchantDocumentTypesEnum::DNI,
                    documentNumber: '001-1202426-7',
                ),
            ),
        ]);
    }
}
