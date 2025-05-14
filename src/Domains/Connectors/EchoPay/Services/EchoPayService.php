<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\EchoPay\Client;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardData;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsultServiceQueryData;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetailData;
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

    public function consultService(ConsultServiceQueryData $data): array
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

    public function addCard(CardData $data)
    {
        $response = $this->client->post(ConfigurationEnum::ADD_CARD_PATH->value, [
            'json' => $data->toArray(),
        ]);

        return [
            "cardNumber" => $response['data']['cardNumber'],
            "expirationDate" => $response['data']['expirationDate'],
            "instrumentIdentifierId" => $response['data']['instrumentIdentifierId'],
            "paymentInstrumentId" => $response['data']['paymentInstrumentId']
        ];
    }

    public function setupPayer($orderCode, $paymentInstrumentId, MerchantDetailData $merchant)
    {
        $response = $this->client->post(ConfigurationEnum::SETUP_PAYER_PATH->value, [
            'json' => [
                'payment' => [
                    'clientReferenceInformation' => [
                        'code' => $orderCode
                    ],
                    'paymentInformation' => [
                        'paymentInstrument' => [
                            'id' => $paymentInstrumentId
                        ]
                    ],
                    'merchant' => $merchant->toArray()
                ]
            ],
        ]);

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

    public function checkPayerEnrollment(PaymentDetailData $payment, MerchantDetailData $merchant)
    {
        $response = $this->client->post(ConfigurationEnum::CHECK_PAYER_ENROLLMENT_PATH->value, [
            'json' => [
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
            ]
        ]);

        return [
            "clientReferenceInformation" => [
                "code" => $response['data']['clientReferenceInformation']['code']
            ],
            "consumerAuthenticationInformation" => [
                "challengeRequired" => $response['data']['consumerAuthenticationInformation']['challengeRequired'],
                "authenticationTransactionId" => $response['data']['consumerAuthenticationInformation']['authenticationTransactionId'],
                "strongAuthentication" => [
                    "OutageExemptionIndicator" => $response['data']['consumerAuthenticationInformation']['strongAuthentication']['OutageExemptionIndicator']
                ],
                "accessToken" => $response['data']['consumerAuthenticationInformation']['accessToken'],
                "token" => $response['data']['consumerAuthenticationInformation']['token']
            ],
            "errorInformation" => [
                "reason" => $response['data']['errorInformation']['reason'],
                "message" => $response['data']['errorInformation']['message']
            ],
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

    public function validatePayerAuthResult(string $transactionId, PaymentDetailData $payment, MerchantDetailData $merchant)
    {
        $response = $this->client->post(ConfigurationEnum::VALIDATE_PAYER_AUTH_RESULT_PATH->value, [
            'json' => [
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
                    "deviceInformation" => $payment->deviceInformation->toArray(),
                    "consumerAuthenticationInformation" => [
                        "authenticationTransactionId" => $transactionId
                    ]
                ],
                "merchant" => $merchant->toArray()
            ]
        ]);


        return [
            "clientReferenceInformation" => [
                "code" => $response['data']['clientReferenceInformation']['code']
            ],
            "consumerAuthenticationInformation" => [
                "indicator" => $response['data']['consumerAuthenticationInformation']['indicator'],
                "eciRaw" => $response['data']['consumerAuthenticationInformation']['eciRaw'],
                "authenticationResult" => $response['data']['consumerAuthenticationInformation']['authenticationResult'],
                "strongAuthentication" => [
                    "OutageExemptionIndicator" => $response['data']['consumerAuthenticationInformation']['strongAuthentication']['OutageExemptionIndicator']
                ],
                "authenticationStatusMsg" => $response['data']['consumerAuthenticationInformation']['authenticationStatusMsg'],
                "effectiveAuthenticationType" => $response['data']['consumerAuthenticationInformation']['effectiveAuthenticationType'],
                "authorizationPayload" => $response['data']['consumerAuthenticationInformation']['authorizationPayload'],
                "eci" => $response['data']['consumerAuthenticationInformation']['eci'],
                "token" => $response['data']['consumerAuthenticationInformation']['token'],
                "cavv" => $response['data']['consumerAuthenticationInformation']['cavv'],
                "paresStatus" => $response['data']['consumerAuthenticationInformation']['paresStatus'],
                "xid" => $response['data']['consumerAuthenticationInformation']['xid'],
                "directoryServerTransactionId" => $response['data']['consumerAuthenticationInformation']['directoryServerTransactionId'],
                "threeDSServerTransactionId" => $response['data']['consumerAuthenticationInformation']['threeDSServerTransactionId'],
                "specificationVersion" => $response['data']['consumerAuthenticationInformation']['specificationVersion'],
                "acsTransactionId" => $response['data']['consumerAuthenticationInformation']['acsTransactionId']
            ],
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

    public function payService(PaymentDetailData $payment, $indicator, MerchantDetailData $merchant, $service)
    {
        $response = $this->client->post(ConfigurationEnum::PAY_SERVICE_PATH->value, [
            'json' => [
                "payment" => [
                    "clientReferenceInformation" => [
                        "code" => $payment->orderCode
                    ],
                    "processingInformation" => [
                        "capture" => true,
                        "commerceIndicator" => $indicator,
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
                    "consumerAuthenticationInformation" => [
                        "accessToken" => $payment->consumerAuthenticationInformation->accessToken,
                        "deviceDataCollectionUrl" => $payment->consumerAuthenticationInformation->deviceDataCollectionUrl,
                        "referenceId" => $payment->consumerAuthenticationInformation->referenceId,
                        "token" => $payment->consumerAuthenticationInformation->token,
                        "eciRaw" => $payment->consumerAuthenticationInformation->eciRaw,
                        "paresStatus" => $payment->consumerAuthenticationInformation->paresStatus,
                        "xid" => $payment->consumerAuthenticationInformation->xid,
                        "ucafCollectionIndicator" => $payment->consumerAuthenticationInformation->ucafCollectionIndicator,
                        "ucafAuthenticationData" => $payment->consumerAuthenticationInformation->ucafAuthenticationData,
                        "strongAuthentication" => [
                            "outageExemptionIndicator" => $payment->consumerAuthenticationInformation->strongAuthentication->outageExemptionIndicator
                        ],
                        "directoryServerTransactionId" => $payment->consumerAuthenticationInformation->directoryServerTransactionId,
                        "paSpecificationVersion" => $payment->consumerAuthenticationInformation->paSpecificationVersion,
                        "acsTransactionId" => $payment->consumerAuthenticationInformation->acsTransactionId,
                        "authenticationTransactionId" => $payment->consumerAuthenticationInformation->authenticationTransactionId
                    ],
                    "deviceInformation" => $payment->deviceInformation->toArray(),
                    "merchantDefinedInformation" => $merchant->toArray()
                ],
                "merchant" => $merchant->toArray(),
                "service" => $service
            ]
        ]);

        return [
            "id" => $response['data']['id'],
            "merchantId" => $response['data']['merchantId'],
            "merchantKey" => $response['data']['merchantKey'],
            "serviceCode" => $response['data']['serviceCode'],
            "contract" => $response['data']['contract'],
            "transactionId" => $response['data']['transactionId'],
            "referenceCode" => $response['data']['referenceCode'],
            "approvalCode" => $response['data']['approvalCode'],
            "amount" => $response['data']['amount'],
            "cardNumber" => $response['data']['cardNumber'],
            "transactionStatus" => $response['data']['transactionStatus'],
            "createdAt" => $response['data']['createdAt'],
            "updatedAt" => $response['data']['updatedAt'],
            "status" => [
                "id" => $response['data']['status']['id'],
                "name" => $response['data']['status']['name'],
                "description" => $response['data']['status']['description'],
                "createdAt" => $response['data']['status']['createdAt']
            ]
        ];
    }
}
