<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\EchoPay\Client;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardTokenization;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsultServiceQuery;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentResponse;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;

class EchoPayService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->client = (new Client($app, $company, $config));
    }

    public function consultService(ConsultServiceQuery $data): array
    {
        $query = http_build_query($data->toArray());
        $response = $this->client->get(ConfigurationEnum::CONSULT_SERVICE_PATH->value . '?' . $query);

        return [
            "serviceCode" => $response['data']['serviceCode'],
            "contractNumber" => $response['data']['contractNumber'],
            "invoiceNumber" => $response['data']['invoiceNumber'],
            "invoiceDate" => $response['data']['invoiceDate'],
            "clientName" => $response['data']['clientName'],
            "currency" => $response['data']['currency'],
            "amount" => $response['data']['amount'],
            "maxAmount" => $response['data']['maxAmount'],
            "minAmount" => $response['data']['minAmount'],
            "chargeId" => $response['data']['chargeId'],
            "validatorId" => $response['data']['validatorId'],
        ];
    }

    public function addCard(CardTokenization $data): array
    {
        $response = $this->client->post(ConfigurationEnum::ADD_CARD_PATH->value, $data->toArray());

        return [
            "cardNumber" => $response['data']['cardNumber'],
            "expirationDate" => $response['data']['expirationDate'],
            "instrumentIdentifierId" => $response['data']['instrumentIdentifierId'],
            "paymentInstrumentId" => $response['data']['paymentInstrumentId']
        ];
    }

    public function setupPayer(string $orderCode, string $paymentInstrumentId, MerchantDetail $merchant): array
    {
        $formData = [
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $orderCode
                ],
                'paymentInformation' => [
                    'paymentInstrument' => [
                        'id' => $paymentInstrumentId
                    ]
                ],
            ],
            'merchant' => $merchant->toArray()
        ];
        $response = $this->client->post(ConfigurationEnum::SETUP_PAYER_PATH->value, $formData);

        return [
            "clientReferenceInformation" => [
                "code" => $response['data']['clientReferenceInformation']['code']
            ],
            "consumerAuthenticationInformation" => [
                "accessToken" => $response['data']['consumerAuthenticationInformation']['accessToken'],
                "deviceDataCollectionUrl" => $response['data']['consumerAuthenticationInformation']['deviceDataCollectionUrl'],
                "referenceId" => $response['data']['consumerAuthenticationInformation']['referenceId'],
                "token" => $response['data']['consumerAuthenticationInformation']['token']
            ],
            "id" => $response['data']['id'],
            "status" => $response['data']['status'],
            "submitTimeUtc" => $response['data']['submitTimeUtc']
        ];
    }

    public function checkPayerEnrollment(PaymentDetail $payment, MerchantDetail $merchant): array
    {
        $formData = [
            "payment" => [
                "clientReferenceInformation" => [
                    "code" => $payment->orderCode
                ],
                "paymentInformation" => [
                    "paymentInstrument" => [
                        "id" => $payment->paymentInstrumentId
                    ]
                ],
                "orderInformation" => [
                    "amountDetails" => [
                        "currency" => $payment->orderInformation->currency,
                        "totalAmount" => $payment->orderInformation->totalAmount
                    ],
                    "billTo" => $payment->orderInformation->billTo->toArray()
                ],
                "deviceInformation" => $payment->deviceInformation->toArray(),
                "consumerAuthenticationInformation" => $payment->consumerAuthenticationInformation->toArray()
            ],
            "merchant" => $merchant->toArray()
        ];


        $response = $this->client->post(ConfigurationEnum::CHECK_PAYER_ENROLLMENT_PATH->value, $formData);

        return [
            "clientReferenceInformation" => [
                "code" => $response['data']['clientReferenceInformation']['code']
            ],
            "consumerAuthenticationInformation" => ConsumerAuthentication::from($response['data']['consumerAuthenticationInformation']),
            "errorInformation" => isset($response['data']['errorInformation']) ? [
                "reason" => $response['data']['errorInformation']['reason'],
                "message" => $response['data']['errorInformation']['message']
            ] : null,
            "id" => $response['data']['id'],
            "paymentInformation" => [
                "card" => [
                    "bin" => $response['data']['paymentInformation']['card']['bin'],
                    "type" => $response['data']['paymentInformation']['card']['type']
                ]
            ],
            "status" => $response['data']['status'],
            "submitTimeUtc" => $response['data']['submitTimeUtc']
        ];
    }

    public function validatePayerAuthResult(
        string $transactionId,
        PaymentDetail $payment,
        MerchantDetail $merchant
    ): array {
        $response = $this->client->post(ConfigurationEnum::VALIDATE_PAYER_AUTH_RESULT_PATH->value, [
            "payment" => [
                "clientReferenceInformation" => [
                    "code" => $payment->orderCode
                ],
                "paymentInformation" => [
                    "paymentInstrument" => [
                        "id" => $payment->paymentInstrumentId
                    ]
                ],
                "orderInformation" => [
                    "amountDetails" => [
                        "currency" => $payment->orderInformation->currency,
                        "totalAmount" => $payment->orderInformation->totalAmount
                    ],
                ],
                "consumerAuthenticationInformation" => [
                    "authenticationTransactionId" => $transactionId
                ]
            ],
            "merchant" => $merchant->toArray()
        ]);

        return [
            "consumerAuthenticationInformation" => ConsumerAuthentication::from($response['data']['consumerAuthenticationInformation']),
            "id" => $response['data']['id'],
            "status" => $response['data']['status'],
        ];
    }

    public function payService(
        PaymentDetail $payment,
        ConsumerAuthentication $consumerAuthenticationData,
        MerchantDetail $merchant,
        array $service
    ): PaymentResponse {
        $formData = [
            "payment" => [
                "clientReferenceInformation" => [
                    "code" => $payment->orderCode
                ],
                "processingInformation" => [
                    "capture" => false,
                    "commerceIndicator" => $consumerAuthenticationData->indicator,
                ],
                "paymentInformation" => [
                    "paymentInstrument" => [
                        "id" => $payment->paymentInstrumentId
                    ]
                ],
                "orderInformation" => [
                    "amountDetails" => [
                        "currency" => $payment->orderInformation->currency,
                        "totalAmount" => $payment->orderInformation->totalAmount
                    ],
                    "billTo" => $payment->orderInformation->billTo->toArray()
                ],
                "consumerAuthenticationInformation" => $consumerAuthenticationData->toArray(),
                "deviceInformation" => $payment->deviceInformation->toArray(),
                "merchantDefinedInformation" => $merchant->merchantDefinedInformation->toArray()
            ],
            "merchant" => [
                "id" => $merchant->id,
                "key" => $merchant->key,
                "secretKey" => $merchant->secretKey,
            ],
            "service" => $service
        ];
        $response = $this->client->post(ConfigurationEnum::PAY_SERVICE_PATH->value, $formData);

        return PaymentResponse::from($response['data']);
    }
}
