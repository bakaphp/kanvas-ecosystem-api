<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

use Exception;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthenticationInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\DeviceInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDefinedInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\OrderInformation;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentCaptureInput;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EnumsPaymentStatusEnum;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

class PortalPaymentProcessor
{
    protected EchoPayService $client;
    protected string $refId;
    protected Payments $payment;

    /**
     * @psalm-suppress UndefinedMagicPropertyFetch
     * @psalm-suppress MixedAssignment
     */
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected array $params = []
    ) {
        $this->client = new EchoPayService($this->app, $this->company);
        $this->refId = 'ref' . time();        // Set the transaction's refId
    }

    protected function setupMerchantAuthentication(Payments $payment, bool $includeDetails = false): MerchantDetail
    {
        return MerchantDetail::from([
            'id' => $this->app->get('ECHO_PAY_MERCHANT_ID'),
            'key' => $this->app->get('ECHO_PAY_MERCHANT_KEY'),
            'secretKey' => $this->app->get('ECHO_PAY_MERCHANT_SECRET'),
            ...($includeDetails
                ? ['merchantDefinedInformation' => new MerchantDefinedInformation(
                    category: MerchantCategoryEnum::RETAIL,
                    cardIdentifier: $this->app->get(ConfigurationEnum::MERCHANT_IDENTIFIER->value) ?? "",
                    platform: MerchantPlatformEnum::WEB,
                    customerId: "user_" . $payment->user->id,
                    tokenization: MerchantTokenizationEnum::TOKENIZATION_YES,
                    documentType: MerchantDocumentTypesEnum::DNI,
                    documentNumber: $this->app->get(ConfigurationEnum::MERCHANT_DOCUMENT_NUMBER->value) ?? "",
                )]
                : [])
        ]);
    }

    protected function setCustomerBillingAddress(Payments $payment, Order $orderInput): BillingDetail
    {
        return new BillingDetail(
            firstName: $payment->paymentMethod->getMetadata('firstname') ?? $orderInput->user->firstname,
            lastName: $payment->paymentMethod->getMetadata('lastname') ?? $orderInput->user->lastname,
            country: $payment->paymentMethod->getMetadata('country'),
            city: $payment->paymentMethod->getMetadata('city'),
            address1: $payment->paymentMethod->getMetadata('address'),
            phone: $payment->paymentMethod->getMetadata('phone'),
            email: $orderInput->user->email,
            postalCode: $payment->paymentMethod->getMetadata('zip_code'),
            administrativeArea: $payment->paymentMethod->getMetadata('state'),
        );
    }

    protected function setupService(Order $orderInput): array
    {
        return [
            "merchantKey" => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value),
            "channelCode" => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_CHANNEL_CODE->value),
            "serviceCode" => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_SERVICE_CODE->value),
            "serviceTypeId" => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_SERVICE_TYPE_ID->value),
            "contract" => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_CONTRACT->value)
        ];
    }

    public function startPaymentIntent(Payments $payment): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication($payment);
        $payerAuthentication = $this->client->setupPayer(
            "order_" . $payment->order->order_number,
            $payment->paymentMethod->stripe_card_id,
            $merchantAuthentication
        );

        return $payerAuthentication;
    }

    public function checkEnrollment(Payments $payment, string $referenceId): array
    {
        $orderInput = $payment->order;
        $merchantAuthentication = $this->setupMerchantAuthentication($payment);

        try {
            $enrollmentData = $this->client->checkPayerEnrollment(
                PaymentDetail::from([
                    'orderCode' => $orderInput->reference . '_' . $orderInput->id,
                    'paymentInstrumentId' => $payment->paymentMethod->stripe_card_id,
                    'orderInformation' => OrderInformation::from([
                        'currency' => 'DOP',
                        'totalAmount' => $orderInput->getTotalAmount(),
                        'billTo' => $this->setCustomerBillingAddress($payment, $orderInput),
                    ]),
                    'deviceInformation' => DeviceInformation::from([
                        "httpAcceptContent" => "application/json",
                        "httpBrowserLanguage" => "en_us",
                        "userAgentBrowserValue" => "chrome"
                    ]),
                    'consumerAuthenticationInformation' => ConsumerAuthenticationInformation::from([
                        "deviceChannel" => "BROWSER",
                        "returnUrl" => $this->app->get(ConfigurationEnum::REDIRECT_URL->value),
                        "referenceId" => $referenceId,
                        "transactionMode" => "eCommerce"
                    ]),
                ]),
                $merchantAuthentication
            );

            $consumerData = ConsumerAuthentication::from($enrollmentData['consumerAuthenticationInformation']);

            if ($this->isValidEci($consumerData, $enrollmentData)) {
                return [
                    'status' => 'success',
                    'message' => 'Payer enrolled',
                    'data' => $consumerData
                ];
            } else {
                return $this->requestUserValidation($payment, $enrollmentData, $referenceId);
            }
        } catch (Throwable $e) {
            report($e);
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->message ?? $e->getMessage();
            } else {
                $errorMessage = $e->getMessage();
            }

            $payment->status = PaymentStatusEnum::FAILED;
            $payment->addMetadata([
                'enrollment_data' => [],
                'error' => $e->getMessage()
            ]);
            $payment->save();

            $payment->order->updateQuietly([
                'status' => OrderStatusEnum::FAILED->value,
                'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
            ]);

            return [
                'status' => 'error',
                'message' => $errorMessage,
                'response' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function validatePayerAuthResult(Payments $payment, Order $order, string $transactionId): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication($payment);

        try {
            $validatedData = $this->client->validatePayerAuthResult(
                $transactionId,
                PaymentDetail::from([
                    'orderCode' =>  $order->reference . '_' . $order->id,
                    'paymentInstrumentId' => $payment->paymentMethod->stripe_card_id,
                    'orderInformation' => OrderInformation::from([
                        'currency' => 'DOP',
                        'totalAmount' => $order->getTotalAmount(),
                    ]),
                ]),
                $merchantAuthentication
            );

            $consumerData = ConsumerAuthentication::from($validatedData['consumerAuthenticationInformation']);

            if ($this->isValidEci($consumerData, $validatedData)) {
                return [
                    'status' => 'success',
                    'message' => 'Payer enrolled',
                    'data' => $consumerData
                ];
            } else {
                return $this->requestUserValidation($payment, $validatedData);
            }
        } catch (Throwable $e) {
            report($e);
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->message ?? $e->getMessage();
            } else {
                $errorMessage = $e->getMessage();
            }

            $payment->status = PaymentStatusEnum::FAILED;
            $payment->addMetadata([
                'enrollment_data' => [],
                'error' => $e->getMessage()
            ]);
            $payment->save();

            $payment->order->updateQuietly([
                'status' => OrderStatusEnum::FAILED->value,
                'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
            ]);

            return [
                'status' => 'error',
                'message' => $errorMessage,
                'response' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    private function isValidEci(ConsumerAuthentication $consumerData, array $enrollmentData): bool
    {
        if ($enrollmentData['status'] !== EnumsPaymentStatusEnum::AUTHENTICATION_SUCCESSFUL->value) {
            return false;
        }

        return in_array($consumerData->eci, [
            '05',
            '06',
        ]);
    }

    //  If the enrollment status is not AUTHENTICATION_SUCCESSFUL it means that the front needs to authenticate the payer
    private function requestUserValidation(Payments $payment, array $enrollmentData): array
    {
        $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION;
        $payment->addMetadata([
            'enrollment_data' => $enrollmentData,
        ]);
        $payment->save();

        $payment->order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($enrollmentData));

        $payment->order->updateQuietly([
            'status' => OrderStatusEnum::PENDING->value,
            'payment_status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value
        ]);

        return [
            'status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            'message' => PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            'data' => ConsumerAuthentication::from($enrollmentData['consumerAuthenticationInformation'])
        ];
    }

    public function processPayment(Payments $payment, ConsumerAuthentication $consumerData, Order $order)
    {
        $paymentResponse = $this->processPaymentCall($payment, $consumerData, $order);

        //  If the payment is successful and the status is PAYED
        if ($paymentResponse['status'] === 'success' && $paymentResponse['data']['status'] === 'AUTHORIZED') {
            $transactionId = (string) $paymentResponse['data']['processorInformation']['transactionId'];
            $intentId = (string) $paymentResponse['data']['id'];

            $payment->status = PaymentStatusEnum::AUTHORIZED;
            $payment->addMetadata([
                'data' => [
                    'payment_response' => $paymentResponse['data'],
                ],
            ]);
            $payment->save();

            $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $intentId);
            $order->set(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value, $transactionId);
            $order->set(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);
            $order->checkPayments();

            return [
                'status' => 'success',
                'message' => 'Payment successful',
                'data' => $paymentResponse['data'],
            ];
        } else {
            throw new Exception('Payment failed');
        }
    }


    private function processPaymentCall(Payments $payment, ConsumerAuthentication $consumerData, Order $order): array
    {
        $referenceId = $order->get('auth_session_id');
        $merchantAuthentication = $this->setupMerchantAuthentication($payment, includeDetails: true);
        $pamentData = PaymentDetail::from([
            'orderCode' => $order->reference . '_' . $order->id,
            'paymentInstrumentId' => $payment->paymentMethod->stripe_card_id,
            'orderInformation' => OrderInformation::from([
                'currency' => 'DOP',
                'totalAmount' => $order->getTotalAmount(),
                'billTo' => $this->setCustomerBillingAddress($payment, $order),
            ]),
            'deviceInformation' => DeviceInformation::from([
                "ipAddress" => request()->ip(),
                "fingerprintSessionId" => "visanetdr_0000000000000023e013b2-d21811eb-ae3f-e90c619c3f54"
            ]),
            'consumerAuthenticationInformation' => ConsumerAuthenticationInformation::from([
                "deviceChannel" => "BROWSER",
                "referenceId" => $referenceId,
                "transactionMode" => "eCommerce"
            ]),
        ]);

        try {
            $result = $this->client->authorizePayment(
                $pamentData,
                $consumerData,
                $merchantAuthentication
            );

            return [
                'status' => 'success',
                'message' => 'Payment successful',
                'data' => $result,
            ];
        } catch (Throwable $e) {
            report($e);

            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->message ?? $e->getMessage();
            } else {
                $errorMessage = $e->getMessage();
            }

            return [
                'status' => 'error',
                'message' => $errorMessage,
                'data' => [
                    'pamentData' => $pamentData,
                    'consumerData' => $consumerData,
                    'merchantAuthentication' => $merchantAuthentication,
                ],
            ];
        }
    }


    public function capturePayment(Payments $payment, Order $order, string $transactionId): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication($payment);
        $capturePayment = $this->client->capturePayment(
            PaymentCaptureInput::from([
                'transactionId' => $transactionId,
                'orderCode' => $order->reference . '_' . $order->id,
                'currency' => 'DOP',
                'totalAmount' => $order->getTotalAmount(),
            ]),
            $merchantAuthentication
        );

        $payment->status = PaymentStatusEnum::PAID;
        $order->updateQuietly([
            'payment_status' => PaymentStatusEnum::PAID->value,
            'status' => OrderStatusEnum::COMPLETED->value,
        ]);
        $payment->addMetadata([
            'data' => [
                ...$payment->metadata['data'],
                'capture_data' => $capturePayment,
            ],
        ]);
        $payment->save();

        return [
            'status' => 'success',
            'message' => 'Payment captured successfully',
            'data' => $capturePayment,
        ];
    }

    public function reversePayment(Payments $payment, Order $order, string $transactionId, string $reason): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication($payment);
        $reversePayment = $this->client->reversePayment(
            PaymentCaptureInput::from([
                'transactionId' => $transactionId,
                'orderCode' => $order->reference . '_' . $order->id,
                'currency' => 'DOP',
                'totalAmount' => $order->getTotalAmount(),
            ]),
            $merchantAuthentication,
            $reason
        );

        $payment->status = PaymentStatusEnum::REVERSED;
        $order->updateQuietly([
            'payment_status' => PaymentStatusEnum::REVERSED->value,
            'status' => OrderStatusEnum::CANCELED->value,
        ]);

        $payment->addMetadata([
            'data' => [
                ...$payment->metadata['data'],
                'reverse_data' => $reversePayment,
            ],
        ]);
        $payment->save();

        return [
            'status' => 'success',
            'message' => 'Payment reversed successfully',
            'data' => $reversePayment,
        ];
    }

    //  process the request with the device data
    public function completeDeviceData(Payments $payment): array
    {
        $order = $payment->order;

        try {
            $enrollmentResult = $this->checkEnrollment($payment, $order->get('auth_session_id'));

            // If user interaction is pending, stop job and wait
            if ($payment->refresh()->status === PaymentStatusEnum::PENDING_AUTHORIZATION->value) {
                return [
                    'payment' => $payment->getId(),
                    'status' => PaymentStatusEnum::PENDING_AUTHORIZATION->value,
                    'message' => 'Payment pending action for order ' . $order->id . '. Waiting for user.',
                    'data' => [
                        ...$enrollmentResult['data']->toArray(),
                        'returnUrl' => $this->app->get(ConfigurationEnum::REDIRECT_URL->value),
                    ],
                ];
            }

            return $enrollmentResult;
        } catch (Throwable $e) {
            $order->updateQuietly([
                'status' => OrderStatusEnum::FAILED->value,
            ]);

            $payment->updateQuietly([
                'status' => PaymentStatusEnum::FAILED->value,
            ]);

            $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($e->getMessage()));

            return [
                'payment' => $payment->getId(),
                'status' => 'error',
                'message' => $e->getMessage(),
                'report' => 'fail',
                'data' => null,
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
