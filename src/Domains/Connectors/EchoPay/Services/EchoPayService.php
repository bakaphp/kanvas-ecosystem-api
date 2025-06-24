<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\EchoPay\Client;
use Kanvas\Connectors\EchoPay\DataTransferObject\CardTokenization;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsultServiceQuery;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentCaptureInput;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentResponse;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Payments\DataTransferObjet\PaymentMethod;

class EchoPayService
{
    protected Client $client;
    protected MerchantDetail $merchant;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->client = (new Client($app, $company, $config));
        $this->merchant = MerchantDetail::from([
            'id' => $app->get(ConfigurationEnum::MERCHANT_ID->value),
            'key' => $app->get(ConfigurationEnum::MERCHANT_KEY->value),
            'secretKey' => $app->get(ConfigurationEnum::MERCHANT_SECRET->value),
        ]);
    }

    public function consultService(ConsultServiceQuery $data): array
    {
        $query = http_build_query($data->toArray());
        $response = $this->client->get(ConfigurationEnum::CONSULT_SERVICE_PATH->value . '?' . $query);

        return [
            'serviceCode' => $response['data']['serviceCode'],
            'contractNumber' => $response['data']['contractNumber'],
            'invoiceNumber' => $response['data']['invoiceNumber'],
            'invoiceDate' => $response['data']['invoiceDate'],
            'clientName' => $response['data']['clientName'],
            'currency' => $response['data']['currency'],
            'amount' => $response['data']['amount'],
            'maxAmount' => $response['data']['maxAmount'],
            'minAmount' => $response['data']['minAmount'],
            'chargeId' => $response['data']['chargeId'],
            'validatorId' => $response['data']['validatorId'],
        ];
    }

    public function addCard(CardTokenization $data): array
    {
        $response = $this->client->post(ConfigurationEnum::CARD_PATH->value, $data->toArray());

        return [
            'cardNumber' => $response['data']['cardNumber'],
            'expirationDate' => $response['data']['expirationDate'],
            'instrumentIdentifierId' => $response['data']['instrumentIdentifierId'],
            'paymentInstrumentId' => $response['data']['paymentInstrumentId'],
        ];
    }

    public function addCardFromRequest(array $request, $user): PaymentMethod
    {
        $card = CardTokenization::fromRequest($request, $this->app, $user);
        $tokenizedCard = $this->addCard($card);

        return new PaymentMethod(
            app: $this->app,
            user: $user,
            company: $this->company,
            instrument_identifier_id: $tokenizedCard['instrumentIdentifierId'],
            payment_ending_numbers: substr($request['number'], strlen($request['number']) - 4, 4),
            payment_methods_brand: $request['brand'],
            stripe_card_id: $tokenizedCard['paymentInstrumentId'],
            expiration_date: $request['expiration_date'],
            zip_code: $card->billTo->postalCode,
            processor: 'portal',
            metadata: [
                ...(isset($request['metadata']) ? $request['metadata'] : []),
                ...$tokenizedCard,
                'country' => $request['country'],
                'city' => $request['city'],
                'address' => $request['address'],
                'phone' => $request['phone'],
                'zip_code' => $request['zip_code'],
                'state' => $request['state'],
                'firstname' => $request['firstname'] ?? null,
                'lastname' => $request['lastname'] ?? null,
            ]
        );
    }

    public function updateCard(string $id, CardTokenization $data): array
    {
        $data = $data->toArray();
        unset($data['card']['number']);
        $response = $this->client->patch(ConfigurationEnum::CARD_PATH->value . '/' . $id, $data);

        $expirationDate = $response['data']['card']['expirationYear'] . '-' . $response['data']['card']['expirationMonth'];

        return [
            'cardNumber' => $expirationDate,
            'expirationDate' => $expirationDate,
            'instrumentIdentifierId' => $response['data']['instrumentIdentifierId'],
            'paymentInstrumentId' => $response['data']['paymentInstrumentId'],
        ];
    }

    public function updateCardFromRequest(PaymentMethod $paymentMethod, array $request): PaymentMethod
    {
        $card = CardTokenization::fromRequest($request, $this->app, $paymentMethod->user);
        $cardId = $paymentMethod->metadata['instrumentIdentifierId'] ?? $paymentMethod->instrument_identifier_id;
        $tokenizedCard = $this->updateCard($cardId, $card);

        return new PaymentMethod(
            app: $this->app,
            user: $paymentMethod->user,
            company: $paymentMethod->company,
            payment_ending_numbers: substr($request['number'], strlen($request['number']) - 4, 4),
            payment_methods_brand: $request['brand'],
            stripe_card_id: $tokenizedCard['paymentInstrumentId'],
            expiration_date: $request['expiration_date'],
            zip_code: $card->billTo->postalCode,
            processor: 'portal',
            metadata: $request['metadata'] ?? [
                ...$tokenizedCard,
                'country' => $request['country'],
                'city' => $request['city'],
                'address' => $request['address'],
                'phone' => $request['phone'],
                'zip_code' => $request['zip_code'],
                'state' => $request['state'],
            ]
        );
    }

    public function deleteCardFromRequest(PaymentMethod $paymentMethod): array
    {
        $query = http_build_query([
            'id' => $this->merchant->id,
            'key' => $this->merchant->key,
            'secretKey' => $this->merchant->secretKey,
        ]);

        $id = $paymentMethod->metadata['paymentInstrumentId'] ?? $paymentMethod->stripe_card_id;

        return $this->deleteCard($id);
    }

    public function deleteCard(string $id): array
    {
        $query = http_build_query([
            'id' => $this->merchant->id,
            'key' => $this->merchant->key,
            'secretKey' => $this->merchant->secretKey,
        ]);

        $response = $this->client->delete(ConfigurationEnum::CARD_PATH->value . '/' . $id . '?' . $query);

        return [
            'id' => $id,
            'message' => $response['message'],
            'status' => $response['statusCode'] ?? 200,
        ];
    }

    public function setupPayer(string $orderCode, string $paymentInstrumentId, MerchantDetail $merchant): array
    {
        $formData = [
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $orderCode,
                ],
                'paymentInformation' => [
                    'paymentInstrument' => [
                        'id' => $paymentInstrumentId,
                    ],
                ],
            ],
            'merchant' => $merchant->toArray(),
        ];
        $response = $this->client->post(ConfigurationEnum::SETUP_PAYER_PATH->value, $formData);

        return [
            'clientReferenceInformation' => [
                'code' => $response['data']['clientReferenceInformation']['code'],
            ],
            'consumerAuthenticationInformation' => [
                'accessToken' => $response['data']['consumerAuthenticationInformation']['accessToken'],
                'deviceDataCollectionUrl' => $response['data']['consumerAuthenticationInformation']['deviceDataCollectionUrl'],
                'referenceId' => $response['data']['consumerAuthenticationInformation']['referenceId'],
                'token' => $response['data']['consumerAuthenticationInformation']['token'],
            ],
            'id' => $response['data']['id'],
            'status' => $response['data']['status'],
            'submitTimeUtc' => $response['data']['submitTimeUtc'],
        ];
    }

    public function checkPayerEnrollment(PaymentDetail $payment, MerchantDetail $merchant): array
    {
        $formData = [
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $payment->orderCode,
                ],
                'paymentInformation' => [
                    'paymentInstrument' => [
                        'id' => $payment->paymentInstrumentId,
                    ],
                ],
                'orderInformation' => [
                    'amountDetails' => [
                        'currency' => $payment->orderInformation->currency,
                        'totalAmount' => $payment->orderInformation->totalAmount,
                    ],
                    'billTo' => $payment->orderInformation->billTo->toArray(),
                ],
                'deviceInformation' => $payment->deviceInformation->toArray(),
                'consumerAuthenticationInformation' => $payment->consumerAuthenticationInformation->toArray(),
            ],
            'merchant' => $merchant->toArray(),
        ];

        $response = $this->client->post(ConfigurationEnum::CHECK_PAYER_ENROLLMENT_PATH->value, $formData);

        Log::info('echo pay enrollment data', $response['data']);

        return [
            'clientReferenceInformation' => [
                'code' => $response['data']['clientReferenceInformation']['code'],
            ],
            "consumerAuthenticationInformation" => ConsumerAuthentication::from([
                "indicator" => $response['data']['consumerAuthenticationInformation']['ecommerceIndicator'] ?? null,
                "authenticationTransactionId" => $response['data']['consumerAuthenticationInformation']['authenticationTransactionId'] ?? null,
                "eciRaw" => $response['data']['consumerAuthenticationInformation']['eciRaw'] ?? null,
                "authenticationResult" => $response['data']['consumerAuthenticationInformation']['authenticationResult'] ?? null,
                "strongAuthentication" => $response['data']['consumerAuthenticationInformation']['strongAuthentication'] ?? null,
                "authenticationStatusMsg" => $response['data']['consumerAuthenticationInformation']['authenticationStatusMsg'] ?? null,
                "eci" => $response['data']['consumerAuthenticationInformation']['eci'] ?? null,
                "accessToken" => $response['data']['consumerAuthenticationInformation']['accessToken'] ?? null,
                "token" => $response['data']['consumerAuthenticationInformation']['token'] ?? null,
                "cavv" => $response['data']['consumerAuthenticationInformation']['cavv'] ?? null,
                "paresStatus" => $response['data']['consumerAuthenticationInformation']['paresStatus'] ?? null,
                "xid" => $response['data']['consumerAuthenticationInformation']['xid'] ?? null,
                "directoryServerTransactionId" => $response['data']['consumerAuthenticationInformation']['directoryServerTransactionId'] ?? null,
                "threeDSServerTransactionId" => $response['data']['consumerAuthenticationInformation']['threeDSServerTransactionId'] ?? null,
                "specificationVersion" => $response['data']['consumerAuthenticationInformation']['specificationVersion'] ?? null,
                "acsTransactionId" => $response['data']['consumerAuthenticationInformation']['acsTransactionId'] ?? null,
                "ucafCollectionIndicator" => $response['data']['consumerAuthenticationInformation']['ucafCollectionIndicator'] ?? "",
                "ucafAuthenticationData" => $response['data']['consumerAuthenticationInformation']['ucafAuthenticationData'] ?? "",
                "challengeRequired" => $response['data']['consumerAuthenticationInformation']['challengeRequired'] ?? "",
                "acsUrl" => $response['data']['consumerAuthenticationInformation']['acsUrl'] ?? "",
                "acsReferenceNumber" => $response['data']['consumerAuthenticationInformation']['acsReferenceNumber'] ?? "",
                "stepUpUrl" => $response['data']['consumerAuthenticationInformation']['stepUpUrl'] ?? "",
                "pareq" => $response['data']['consumerAuthenticationInformation']['pareq'] ?? "",
                "veresEnrolled" => $response['data']['consumerAuthenticationInformation']['veresEnrolled'] ?? "",
                "acsOperatorID" => $response['data']['consumerAuthenticationInformation']['acsOperatorID'] ?? "",
            ]),
            "errorInformation" => isset($response['data']['errorInformation']) ? [
                "reason" => $response['data']['errorInformation']['reason'],
                "message" => $response['data']['errorInformation']['message']
            ] : null,
            'id' => $response['data']['id'],
            'paymentInformation' => [
                'card' => [
                    'bin' => $response['data']['paymentInformation']['card']['bin'],
                    'type' => $response['data']['paymentInformation']['card']['type'],
                ],
            ],
            'status' => $response['data']['status'],
            'submitTimeUtc' => $response['data']['submitTimeUtc'],
        ];
    }

    public function validatePayerAuthResult(
        string $transactionId,
        PaymentDetail $payment,
        MerchantDetail $merchant
    ): array {
        $response = $this->client->post(ConfigurationEnum::VALIDATE_PAYER_AUTH_RESULT_PATH->value, [
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $payment->orderCode,
                ],
                'paymentInformation' => [
                    'paymentInstrument' => [
                        'id' => $payment->paymentInstrumentId,
                    ],
                ],
                'orderInformation' => [
                    'amountDetails' => [
                        'currency' => $payment->orderInformation->currency,
                        'totalAmount' => $payment->orderInformation->totalAmount,
                    ],
                ],
                'consumerAuthenticationInformation' => [
                    'authenticationTransactionId' => $transactionId,
                ],
            ],
            'merchant' => $merchant->toArray(),
        ]);

        $consumerInformation = $response['data']['consumerAuthenticationInformation'] ?? null;

        return [
            'clientReferenceInformation' => [
                'code' => $response['data']['clientReferenceInformation']['code'],
            ],
            "consumerAuthenticationInformation" => ConsumerAuthentication::from([
                "indicator" => $consumerInformation['ecommerceIndicator'] ?? $consumerInformation['indicator'] ?? null,
                "authenticationTransactionId" => $consumerInformation['authenticationTransactionId'] ?? null,
                "eciRaw" => $consumerInformation['eciRaw'] ?? null,
                "authenticationResult" => $consumerInformation['authenticationResult'] ?? null,
                "strongAuthentication" => $consumerInformation['strongAuthentication'] ?? null,
                "authenticationStatusMsg" => $consumerInformation['authenticationStatusMsg'] ?? null,
                "eci" => $consumerInformation['eci'] ?? null,
                "accessToken" => $consumerInformation['accessToken'] ?? null,
                "token" => $consumerInformation['token'] ?? null,
                "cavv" => $consumerInformation['cavv'] ?? null,
                "paresStatus" => $consumerInformation['paresStatus'] ?? null,
                "xid" => $consumerInformation['xid'] ?? null,
                "directoryServerTransactionId" => $consumerInformation['directoryServerTransactionId'] ?? null,
                "threeDSServerTransactionId" => $consumerInformation['threeDSServerTransactionId'] ?? null,
                "specificationVersion" => $consumerInformation['specificationVersion'] ?? null,
                "acsTransactionId" => $consumerInformation['acsTransactionId'] ?? null,
                "ucafCollectionIndicator" => $consumerInformation['ucafCollectionIndicator'] ?? "",
                "ucafAuthenticationData" => $consumerInformation['ucafAuthenticationData'] ?? "",
                "challengeRequired" => $consumerInformation['challengeRequired'] ?? "",
                "acsUrl" => $consumerInformation['acsUrl'] ?? "",
                "acsReferenceNumber" => $consumerInformation['acsReferenceNumber'] ?? "",
                "stepUpUrl" => $consumerInformation['stepUpUrl'] ?? "",
                "pareq" => $consumerInformation['pareq'] ?? "",
                "veresEnrolled" => $consumerInformation['veresEnrolled'] ?? "",
                "acsOperatorID" => $consumerInformation['acsOperatorID'] ?? "",
            ]),
            "errorInformation" => isset($response['data']['errorInformation']) ? [
                "reason" => $response['data']['errorInformation']['reason'],
                "message" => $response['data']['errorInformation']['message']
            ] : null,
            'id' => $response['data']['id'],
            'status' => $response['data']['status']
        ];
    }

    public function payService(
        PaymentDetail $payment,
        ConsumerAuthentication $consumerData,
        MerchantDetail $merchant,
        array $service
    ): PaymentResponse {
        $formData = [
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $payment->orderCode,
                ],
                'processingInformation' => [
                    'capture' => false,
                    'commerceIndicator' => $consumerData->indicator,
                ],
                'paymentInformation' => [
                    'paymentInstrument' => [
                        'id' => $payment->paymentInstrumentId,
                    ],
                ],
                'orderInformation' => [
                    'amountDetails' => [
                        'currency' => $payment->orderInformation->currency,
                        'totalAmount' => $payment->orderInformation->totalAmount,
                    ],
                    'billTo' => $payment->orderInformation->billTo->toArray(),
                ],
                'consumerAuthenticationInformation' => $consumerData->toArray(),
                'deviceInformation' => $payment->deviceInformation->toArray(),
                'merchantDefinedInformation' => $merchant->merchantDefinedInformation->toArray(),
            ],
            'merchant' => [
                'id' => $merchant->id,
                'key' => $merchant->key,
                'secretKey' => $merchant->secretKey,
            ],
            'service' => $service,
        ];

        Log::info('echo pay pay service data', $formData);
        $response = $this->client->post(ConfigurationEnum::PAY_SERVICE_PATH->value, $formData);

        return PaymentResponse::from($response['data']);
    }

    public function authorizePayment(
        PaymentDetail $payment,
        ConsumerAuthentication $consumerData,
        MerchantDetail $merchant
    ): array {
        $formData = [
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $payment->orderCode,
                ],
                'processingInformation' => [
                    'capture' => false,
                    'commerceIndicator' => $consumerData->indicator,
                ],
                'paymentInformation' => [
                    'paymentInstrument' => [
                        'id' => $payment->paymentInstrumentId,
                    ],
                ],
                'orderInformation' => [
                    'amountDetails' => [
                        'currency' => $payment->orderInformation->currency,
                        'totalAmount' => $payment->orderInformation->totalAmount,
                    ],
                    'billTo' => $payment->orderInformation->billTo->toArray(),
                ],
                'consumerAuthenticationInformation' => $consumerData->toArray(),
                'deviceInformation' => $payment->deviceInformation->toArray(),
                'merchantDefinedInformation' => $merchant->merchantDefinedInformation->toArray(),
            ],
            'merchant' => [
                'id' => $merchant->id,
                'key' => $merchant->key,
                'secretKey' => $merchant->secretKey,
            ]
        ];
        $response = $this->client->post(ConfigurationEnum::AUTHORIZE_PAYMENT_PATH->value, $formData);

        return $response['data'];
    }

    public function capturePayment(
        PaymentCaptureInput $payment,
        MerchantDetail $merchant
    ): array {
        $formData = [
            'transactionId' => $payment->transactionId,
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $payment->orderCode,
                ],
                'orderInformation' => [
                    'amountDetails' => [
                        'currency' => $payment->currency,
                        'totalAmount' => $payment->totalAmount,
                    ]
                ],
            ],
            'merchant' => [
                'id' => $merchant->id,
                'key' => $merchant->key,
                'secretKey' => $merchant->secretKey,
            ]
        ];
        $response = $this->client->post(ConfigurationEnum::CAPTURE_PAYMENT_PATH->value, $formData);

        return $response['data'];
    }

    public function reversePayment(
        PaymentCaptureInput $payment,
        MerchantDetail $merchant,
        string $reason = "test"
    ): array {
        $formData = [
            'transactionId' => $payment->transactionId,
            'payment' => [
                'clientReferenceInformation' => [
                    'code' => $payment->orderCode,
                ],
                'reversalInformation' => [
                    'amountDetails' => [
                        'currency' => $payment->currency,
                        'totalAmount' => $payment->totalAmount,
                    ],
                    "reason" => $reason,
                ],
            ],
            'merchant' => [
                'id' => $merchant->id,
                'key' => $merchant->key,
                'secretKey' => $merchant->secretKey,
            ]
        ];
        $response = $this->client->post(ConfigurationEnum::REVERSAL_PAYMENT_PATH->value, $formData);

        return $response['data'];
    }
}
