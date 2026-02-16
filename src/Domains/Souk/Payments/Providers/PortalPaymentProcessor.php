<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

use GuzzleHttp\Exception\ConnectException;
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
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
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

    public function setImmediateCapture(bool $immediateCapture): void
    {
        $this->params['immediate_capture'] = $immediateCapture;
    }

    protected function getMerchantCredentials(Order $order): array
    {
        $isMultiMerchant = $this->app->get('portal_multy_merchant') === 1;
        $orderTypeName = $order->orderType?->name;

        if ($isMultiMerchant && $orderTypeName) {
            return [
                'id' => $this->app->get($orderTypeName . '_ECHO_PAY_MERCHANT_ID'),
                'key' => $this->app->get($orderTypeName . '_ECHO_PAY_MERCHANT_KEY'),
                'secretKey' => $this->app->get($orderTypeName . '_ECHO_PAY_MERCHANT_SECRET'),
            ];
        }

        return [
            'id' => $this->app->get('ECHO_PAY_MERCHANT_ID') ?? '',
            'key' => $this->app->get('ECHO_PAY_MERCHANT_KEY') ?? '',
            'secretKey' => $this->app->get('ECHO_PAY_MERCHANT_SECRET') ?? '',
        ];
    }

    protected function setupMerchantAuthentication(Payments $payment, Order $order, bool $includeDetails = false): MerchantDetail
    {
        $credentials = $this->getMerchantCredentials($order);

        if (! $credentials['id']) {
            $orderTypeName = $order->orderType?->name;
            $isMultiMerchant = $this->app->get('portal_multy_merchant') === 1;

            if ($isMultiMerchant && $orderTypeName) {
                throw new \Exception("Missing merchant credentials for order type '{$orderTypeName}'. Please configure {$orderTypeName}_ECHO_PAY_MERCHANT_ID, {$orderTypeName}_ECHO_PAY_MERCHANT_KEY, and {$orderTypeName}_ECHO_PAY_MERCHANT_SECRET.");
            } else {
                throw new \Exception('Missing default merchant credentials. Please configure ECHO_PAY_MERCHANT_ID, ECHO_PAY_MERCHANT_KEY, and ECHO_PAY_MERCHANT_SECRET.');
            }
        }

        return MerchantDetail::from([
            ...$credentials,
            ...($includeDetails
                ? ['merchantDefinedInformation' => new MerchantDefinedInformation(
                    category: MerchantCategoryEnum::RETAIL,
                    cardIdentifier: $credentials['id'],
                    platform: MerchantPlatformEnum::MOBILE,
                    customerId: 'user_' . $payment->user->id,
                    tokenization: MerchantTokenizationEnum::TOKENIZATION_YES,
                    documentType: MerchantDocumentTypesEnum::DNI,
                    documentNumber: (string) ($payment->user->get('driver_license') ?? ''),
                )]
                : []),
        ]);
    }

    protected function setCustomerBillingAddress(Payments $payment, Order $orderInput): BillingDetail
    {
        return new BillingDetail(
            firstName: $payment->paymentMethod->getMetadata('firstname') ?? $payment->user->firstname,
            lastName: $payment->paymentMethod->getMetadata('lastname') ?? $payment->user->lastname,
            country: $payment->paymentMethod->getMetadata('country'),
            city: $payment->paymentMethod->getMetadata('city'),
            address1: $payment->paymentMethod->getMetadata('address'),
            phone: $payment->paymentMethod->getMetadata('phone'),
            email: $payment->user->email,
            postalCode: $payment->paymentMethod->getMetadata('zip_code'),
            administrativeArea: $payment->paymentMethod->getMetadata('state'),
        );
    }

    protected function setupService(Order $orderInput): array
    {
        return [
            'merchantKey' => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value),
            'channelCode' => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_CHANNEL_CODE->value),
            'serviceCode' => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_SERVICE_CODE->value),
            'serviceTypeId' => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_SERVICE_TYPE_ID->value),
            'contract' => (string) $orderInput->get(CustomFieldEnum::ECHO_PAY_CONTRACT->value),
        ];
    }

    public function startPaymentIntent(Payments $payment): array
    {
        $payment->addLog('payment_initiated', [
            'order_id' => $payment->order->id,
            'amount' => $payment->amount,
        ]);

        $merchantAuthentication = $this->setupMerchantAuthentication($payment, $payment->order);
        $payerAuthentication = $this->client->setupPayer(
            $payment->order->id,
            $payment->paymentMethod->stripe_card_id,
            $merchantAuthentication
        );

        return $payerAuthentication;
    }

    public function checkEnrollment(Payments $payment, string $referenceId): array
    {
        $orderInput = $payment->order;
        $merchantAuthentication = $this->setupMerchantAuthentication($payment, $orderInput);
        $enrollmentData = [];

        try {
            $enrollmentData = $this->client->checkPayerEnrollment(
                PaymentDetail::from([
                    'orderCode' => $orderInput->id,
                    'paymentInstrumentId' => $payment->paymentMethod->stripe_card_id,
                    'orderInformation' => OrderInformation::from([
                        'currency' => 'DOP',
                        'totalAmount' => $orderInput->getTotalAmount(),
                        'billTo' => $this->setCustomerBillingAddress($payment, $orderInput),
                    ]),
                    'deviceInformation' => DeviceInformation::from([
                        'httpAcceptContent' => 'application/json',
                        'httpBrowserLanguage' => 'en_us',
                        'userAgentBrowserValue' => 'chrome',
                    ]),
                    'consumerAuthenticationInformation' => ConsumerAuthenticationInformation::from([
                        'deviceChannel' => 'BROWSER',
                        'returnUrl' => $this->app->get(ConfigurationEnum::REDIRECT_URL->value),
                        'referenceId' => $referenceId,
                        'transactionMode' => 'eCommerce',
                    ]),
                ]),
                $merchantAuthentication
            );

            $consumerData = ConsumerAuthentication::from($enrollmentData['consumerAuthenticationInformation']);

            if ($this->isValidEci($consumerData, $enrollmentData)) {
                $payment->updateQuietly([
                    'status' => PaymentStatusEnum::WAITING_DEVICE_DATA->value,
                ]);

                return [
                    'status' => 'success',
                    'message' => 'Payer enrolled',
                    'data' => $consumerData,
                ];
            } else {
                return $this->requestUserValidation($payment, $enrollmentData, $referenceId);
            }
        } catch (EchoPayException $e) {
            report($e);
            $errorMessage = $e->getMessage();
            $userMessage = $e->getUserMessage();

            $payment->status = PaymentStatusEnum::FAILED;
            $payment->addMetadata([
                'enrollment_data' => $enrollmentData,
                'error' => $errorMessage,
                'echopay_error' => $e->getErrorBody(),
                'echopay_error_timestamp' => now()->toIso8601String(),
            ]);
            $payment->save();

            $payment->order->updateQuietly([
                'status' => OrderStatusEnum::FAILED->value,
                'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
            ]);

            return [
                'status' => 'error',
                'message' => $userMessage,
                'response' => $e->getMessage(),
                'data' => [],
            ];
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
                'enrollment_data' => $enrollmentData,
                'error' => $errorMessage,
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
        $merchantAuthentication = $this->setupMerchantAuthentication($payment, $order);
        $consumerData = [];

        try {
            $validatedData = $this->client->validatePayerAuthResult(
                $transactionId,
                PaymentDetail::from([
                    'orderCode' => $order->id,
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
                $payment->updateQuietly([
                    'status' => PaymentStatusEnum::WAITING_DEVICE_DATA->value,
                ]);

                return [
                    'status' => 'success',
                    'message' => 'Payer enrolled',
                    'data' => $consumerData,
                ];
            } else {
                return $this->requestUserValidation($payment, $validatedData);
            }
        } catch (EchoPayException $e) {
            report($e);
            $errorMessage = $e->getMessage();
            $userMessage = $e->getUserMessage();

            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->addMetadata([
                'enrollment_data' => $consumerData,
                'error' => $errorMessage,
                'echopay_error' => $e->getErrorBody(),
                'echopay_error_timestamp' => now()->toIso8601String(),
            ]);
            $payment->save();

            $payment->order->updateQuietly([
                'status' => OrderStatusEnum::FAILED->value,
                'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
            ]);

            return [
                'status' => 'error',
                'message' => $userMessage,
                'response' => $e->getMessage(),
                'data' => [],
            ];
        } catch (Throwable $e) {
            report($e);
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->message ?? $e->getMessage();
            } else {
                $errorMessage = $e->getMessage();
            }

            $payment->status = PaymentStatusEnum::FAILED->value;
            $payment->addMetadata([
                'enrollment_data' => $consumerData,
                'error' => $errorMessage,
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

        $eci = $consumerData->eci ?? $consumerData->eciRaw;
        $hasValidEci = in_array($eci, [
            '02',
            '05',
        ]);

        if (isset($enrollmentData['paymentInformation']['card']['type'])) {
            $cardBrand = $enrollmentData['paymentInformation']['card']['type'];
            $isEciMissing = $enrollmentData['status'] === EnumsPaymentStatusEnum::AUTHENTICATION_SUCCESSFUL->value && ! $consumerData->eci;
            $byPassEci = $this->app->get(ConfigurationEnum::BYPASS_ECI->value);
            // If the card brand is MASTERCARD and the ECI is missing, we consider the payment as successful
            if ($cardBrand === 'MASTERCARD' && $isEciMissing && $byPassEci) {
                return true;
            }
        }

        return $hasValidEci;
    }

    //  If the enrollment status is not AUTHENTICATION_SUCCESSFUL it means that the front needs to authenticate the payer
    private function requestUserValidation(Payments $payment, array $enrollmentData): array
    {
        $statusMap = [
            EnumsPaymentStatusEnum::AUTHENTICATION_SUCCESSFUL->value => PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            EnumsPaymentStatusEnum::AUTHENTICATION_FAILED->value => PaymentStatusEnum::FAILED->value,
            EnumsPaymentStatusEnum::PENDING_AUTHENTICATION->value => PaymentStatusEnum::PENDING_AUTHORIZATION->value,
        ];

        $paymentStatus = $statusMap[$enrollmentData['status']];
        $payment->status = $paymentStatus;
        $payment->addMetadata([
            'enrollment_data' => $enrollmentData,
        ]);
        $payment->save();

        $payment->order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($enrollmentData));

        $payment->order->updateQuietly([
            'status' => $paymentStatus === PaymentStatusEnum::PENDING_AUTHORIZATION->value ? OrderStatusEnum::PENDING->value : OrderStatusEnum::FAILED->value,
            'payment_status' => $paymentStatus,
        ]);

        $errors = $this->extractErrorsFromEnrollment($enrollmentData);

        return [
            'status' => $paymentStatus,
            'message' => $paymentStatus . $errors['message'],
            'data' => ConsumerAuthentication::from($enrollmentData['consumerAuthenticationInformation']),
        ];
    }

    public function processPayment(Payments $payment, ConsumerAuthentication $consumerData, Order $order): array
    {
        $paymentResponse = $this->processPaymentCall($payment, $consumerData, $order);

        //  If the payment is successful and the status is PAYED
        if ($paymentResponse['status'] === 'success' && $paymentResponse['data']['status'] === 'AUTHORIZED') {
            $transactionId = (string) $paymentResponse['data']['processorInformation']['transactionId'];
            $intentId = (string) $paymentResponse['data']['id'];

            $payment->addLog('payment_authorized', [
                'transaction_id' => $transactionId,
                'intent_id' => $intentId,
                'order_id' => $order->id,
                'amount' => $order->getTotalAmount(),
            ]);

            $payment->status = PaymentStatusEnum::AUTHORIZED;
            $payment->payment_intent_id = $intentId;
            $payment->addMetadata([
                'data' => [
                    'payment_response' => $paymentResponse['data'],
                ],
            ]);
            $payment->save();

            $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $intentId);
            $order->set(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value, $transactionId);
            $order->set(CustomFieldEnum::ECHO_PAY_SHOULD_CAPTURE->value, 1);

            return [
                'status' => 'success',
                'message' => 'Payment successful',
                'data' => $paymentResponse['data'],
            ];
        } else {
            if ($payment->status === PaymentStatusEnum::PROCESSING->value || $payment->status === PaymentStatusEnum::PROCESSING) {
                $payment->update(['status' => PaymentStatusEnum::FAILED->value]);
            }

            return [
                'status' => $paymentResponse['status'],
                'message' => $paymentResponse['message'],
                'data' => $paymentResponse['data'],
            ];
        }
    }

    private function processPaymentCall(Payments $payment, ConsumerAuthentication $consumerData, Order $order): array
    {
        $payment->addLog('payment_processing', [
            'order_id' => $order->id,
            'amount' => $order->getTotalAmount(),
        ]);

        $referenceId = $order->get('auth_session_id');
        $merchantAuthentication = $this->setupMerchantAuthentication($payment, $order, includeDetails: true);
        $paymentData = PaymentDetail::from([
            'orderCode' => $order->id,
            'paymentInstrumentId' => $payment->paymentMethod->stripe_card_id,
            'orderInformation' => OrderInformation::from([
                'currency' => 'DOP',
                'totalAmount' => $order->getTotalAmount(),
                'billTo' => $this->setCustomerBillingAddress($payment, $order),
            ]),
            'deviceInformation' => DeviceInformation::from([
                'ipAddress' => $order->metadata['data']['user_ip'] ?? request()->ip(),
                'fingerprintSessionId' => $merchantAuthentication->id . $order->id,
            ]),
            'consumerAuthenticationInformation' => ConsumerAuthenticationInformation::from([
                'deviceChannel' => 'BROWSER',
                'referenceId' => $referenceId,
                'transactionMode' => 'eCommerce',
            ]),
        ]);

        try {
            $result = $this->client->authorizePayment(
                $paymentData,
                $consumerData,
                $merchantAuthentication
            );

            return [
                'status' => 'success',
                'message' => 'Payment successful',
                'data' => $result,
            ];
        } catch (EchoPayException $e) {
            report($e);
            $errorMessage = $e->getMessage();
            $errorBody = $e->getErrorBody();
            $userMessage = $e->getUserMessage();

            $payment->addLog('payment_error', [
                'error_type' => 'EchoPayException',
                'error_message' => $errorMessage,
                'error_body' => $errorBody,
                'order_id' => $order->id,
            ]);

            $payment->status = PaymentStatusEnum::FAILED->value;
            $order->updateQuietly([
                'payment_status' => PaymentStatusEnum::FAILED->value,
                'status' => OrderStatusEnum::FAILED->value,
                'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
            ]);

            $payment->addMetadata([
                'data' => [
                    ...isset($payment->metadata['data']) ? $payment->metadata['data'] : [],
                    'error' => $errorMessage,
                    'echopay_error' => $errorBody,
                    'user_message' => $userMessage,
                    'echopay_error_timestamp' => now()->toIso8601String(),
                ],
            ]);
            $payment->save();

            return [
                'status' => 'error',
                'message' => $userMessage,
                'data' => [
                    'message_body' => $errorBody,
                    'paymentData' => $paymentData,
                    'consumerData' => $consumerData,
                    'merchantAuthentication' => $merchantAuthentication,
                ],
            ];
        } catch (Throwable $e) {
            report($e);

            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $errorMessage = json_decode((string) $response->getBody())->message ?? $e->getMessage();
                $messageBody = json_decode((string) $response->getBody())->data ?? [];
            } else {
                $errorMessage = $e->getMessage();
                $messageBody = [];
            }

            $payment->addLog('payment_error', [
                'error_type' => get_class($e),
                'error_message' => $errorMessage,
                'order_id' => $order->id,
            ]);

            $payment->status = PaymentStatusEnum::FAILED->value;
            $order->updateQuietly([
                'payment_status' => PaymentStatusEnum::FAILED->value,
                'status' => OrderStatusEnum::FAILED->value,
                'fulfillment_status' => OrderFulfillmentStatusEnum::CANCELLED->value,
            ]);

            $payment->addMetadata([
                'data' => [
                    ...isset($payment->metadata['data']) ? $payment->metadata['data'] : [],
                    'error' => $errorMessage,
                    'message_body' => $messageBody,
                ],
            ]);
            $payment->save();

            return [
                'status' => 'error',
                'message' => $errorMessage,
                'data' => [
                    'message_body' => $messageBody,
                    'paymentData' => $paymentData,
                    'consumerData' => $consumerData,
                    'merchantAuthentication' => $merchantAuthentication,
                ],
            ];
        }
    }

    public function capturePayment(Payments $payment, Order $order, string $transactionId): array
    {
        try {
            $payment->addLog('payment_capture_sending', [
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'amount' => $order->getTotalAmount(),
            ]);

            $merchantAuthentication = $this->setupMerchantAuthentication($payment, $order);
            $capturePayment = $this->client->capturePayment(
                PaymentCaptureInput::from([
                    'transactionId' => $transactionId,
                    'orderCode' => $order->id,
                    'currency' => 'DOP',
                    'totalAmount' => $order->getTotalAmount(),
                ]),
                $merchantAuthentication
            );

            $payment->addLog('payment_captured', [
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'amount' => $order->getTotalAmount(),
            ]);

            $payment->markAsPaid([
                'data' => [
                    'capture_data' => $capturePayment,
                ],
            ]);

            return [
                'status' => 'success',
                'message' => 'Payment captured successfully',
                'data' => $capturePayment,
            ];
        } catch (ConnectException | RequestException $e) {
            // Timeout occurred - provider likely captured but response didn't arrive
            // Assume success and mark as backup_capture for reconciliation
            report($e);

            $payment->addLog('payment_capture_timeout', [
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'amount' => $order->getTotalAmount(),
                'error_message' => $e->getMessage(),
                'assumed_captured' => true,
            ]);

            $payment->markAsPaid([
                'data' => [
                    'backup_capture' => true,
                    'capture_timeout' => [
                        'timestamp' => now()->toIso8601String(),
                        'error' => $e->getMessage(),
                    ],
                ],
            ]);

            return [
                'status' => 'success',
                'message' => 'Payment assumed captured (timeout - needs verification)',
                'data' => ['backup_capture' => true],
            ];
        } catch (EchoPayException $e) {
            report($e);

            $payment->addLog('payment_error', [
                'error_type' => 'EchoPayException',
                'error_message' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'order_id' => $order->id,
                'context' => 'capture_payment',
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => $e->getErrorBody(),
            ];
        } catch (Throwable $e) {
            report($e);

            $payment->addLog('payment_error', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'order_id' => $order->id,
                'context' => 'capture_payment',
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function reversePayment(Payments $payment, Order $order, string $transactionId, string $reason): array
    {
        try {
            $merchantAuthentication = $this->setupMerchantAuthentication($payment, $order);
            $reversePayment = $this->client->reversePayment(
                PaymentCaptureInput::from([
                    'transactionId' => $transactionId,
                    'orderCode' => $order->id,
                    'currency' => 'DOP',
                    'totalAmount' => $order->getTotalAmount(),
                ]),
                $merchantAuthentication,
                $reason
            );

            $payment->addLog('payment_reversed', [
                'transaction_id' => $transactionId,
                'reason' => $reason,
                'order_id' => $order->id,
                'amount' => $order->getTotalAmount(),
            ]);

            $payment->status = PaymentStatusEnum::REVERSED->value;

            $payment->addMetadata([
                'data' => [
                    ...($payment->metadata['data'] ?? []),
                    'reverse_data' => $reversePayment,
                ],
            ]);
            $payment->save();

            return [
                'status' => 'success',
                'message' => 'Payment reversed successfully',
                'data' => $reversePayment,
            ];
        } catch (EchoPayException $e) {
            report($e);

            $payment->addLog('payment_error', [
                'error_type' => 'EchoPayException',
                'error_message' => $e->getMessage(),
                'error_body' => $e->getErrorBody(),
                'transaction_id' => $transactionId,
                'reason' => $reason,
                'order_id' => $order->id,
                'context' => 'reverse_payment',
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => $e->getErrorBody(),
            ];
        } catch (Throwable $e) {
            report($e);

            $payment->addLog('payment_error', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'transaction_id' => $transactionId,
                'reason' => $reason,
                'order_id' => $order->id,
                'context' => 'reverse_payment',
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
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
                    'status' => $enrollmentResult['status'],
                    'message' => 'Payment pending action for order ' . $order->id . '. Waiting for user.',
                    'data' => [
                        ...$enrollmentResult['data']->toArray(),
                        'returnUrl' => $this->app->get(ConfigurationEnum::REDIRECT_URL->value),
                    ],
                ];
            }

            return $enrollmentResult;
        } catch (EchoPayException $e) {
            $userMessage = $e->getUserMessage();

            $order->updateQuietly([
                'status' => OrderStatusEnum::FAILED->value,
            ]);

            $payment->addMetadata([
                'echopay_error' => $e->getErrorBody(),
                'echopay_error_message' => $e->getMessage(),
                'echopay_error_timestamp' => now()->toIso8601String(),
            ]);
            $payment->updateQuietly([
                'status' => PaymentStatusEnum::FAILED->value,
            ]);

            $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, $e->getMessage());

            return [
                'payment' => $payment->getId(),
                'status' => 'error',
                'message' => $userMessage,
                'report' => 'fail',
                'data' => null,
                'trace' => $e->getTraceAsString(),
            ];
        } catch (Throwable $e) {
            $order->updateQuietly([
                'status' => OrderStatusEnum::FAILED->value,
            ]);

            $payment->updateQuietly([
                'status' => PaymentStatusEnum::FAILED->value,
            ]);

            $order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, $e->getMessage());

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

    private function extractErrorsFromEnrollment(array $responseResult): array
    {
        $data = [
            'message' => "",
            'code' => "",
        ];

        if (isset($responseResult['errorInformation']) && is_array($responseResult['errorInformation'])) {
            $data['message'] = " - " . $responseResult['errorInformation']['message'] ?? '';
        }

        return $data;
    }
}
