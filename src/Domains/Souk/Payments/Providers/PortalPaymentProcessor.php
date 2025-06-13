<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

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
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentResponse;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
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
        protected Companies $company
    ) {
        $this->client = new EchoPayService($this->app, $this->company);
        $this->refId = 'ref' . time();        // Set the transaction's refId
    }

    protected function setupMerchantAuthentication(bool $includeDetails = false): MerchantDetail
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
                    customerId: "user_" . $this->payment->order->user->id,
                    tokenization: MerchantTokenizationEnum::TOKENIZATION_YES,
                    documentType: MerchantDocumentTypesEnum::DNI,
                    documentNumber: $this->app->get(ConfigurationEnum::MERCHANT_DOCUMENT_NUMBER->value) ?? "",
                )]
                : [])
        ]);
    }

    protected function setupPayerAuthentication(MerchantDetail $merchantAuthentication, string $paymentInstrumentId): array
    {
        return $this->client->setupPayer($this->refId, $paymentInstrumentId, $merchantAuthentication);
    }

    protected function setCustomerBillingAddress(Order $orderInput): BillingDetail
    {
        return new BillingDetail(
            firstName: $orderInput->user->firstname,
            lastName: $orderInput->user->lastname,
            country: $this->payment->paymentMethod->getMetadata('country'),
            city: $this->payment->paymentMethod->getMetadata('city'),
            address1: $this->payment->paymentMethod->getMetadata('address'),
            phone: $this->payment->paymentMethod->getMetadata('phone'),
            email: $orderInput->user->email,
            postalCode: $this->payment->paymentMethod->getMetadata('zip_code'),
            administrativeArea: $this->payment->paymentMethod->getMetadata('state'),
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

    public function startPaymentIntent(): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication();
        $payerAuthentication = $this->client->setupPayer(
            $this->refId,
            $this->payment->paymentMethod->stripe_card_id,
            $merchantAuthentication
        );

        return $payerAuthentication;
    }

    public function checkEnrollment(Order $orderInput, string $referenceId): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication();
        $enrollmentCheck = $this->client->checkPayerEnrollment(
            PaymentDetail::from([
                'orderCode' => $orderInput->reference . '_' . $orderInput->id,
                'paymentInstrumentId' => $this->payment->paymentMethod->stripe_card_id,
                'orderInformation' => OrderInformation::from([
                    'currency' => 'DOP',
                    'totalAmount' => $orderInput->getTotalAmount(),
                    'billTo' => $this->setCustomerBillingAddress($orderInput),
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

        return $enrollmentCheck;
    }

    public function processPayment(Payments $payment, ConsumerAuthentication $consumerData, $referenceId): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication(includeDetails: true);
        $service = $this->setupService($payment->order);
        $pamentData = PaymentDetail::from([
            'orderCode' => $payment->order->reference . '_' . $payment->order->id,
            'paymentInstrumentId' => $payment->paymentMethod->stripe_card_id,
            'orderInformation' => OrderInformation::from([
                'currency' => 'DOP',
                'totalAmount' => $payment->order->getTotalAmount(),
                'billTo' => $this->setCustomerBillingAddress($payment->order),
            ]),
            'deviceInformation' => DeviceInformation::from([
                "httpAcceptContent" => "application/json",
                "httpBrowserLanguage" => "en_us",
                "userAgentBrowserValue" => "chrome"
            ]),
            'consumerAuthenticationInformation' => ConsumerAuthenticationInformation::from([
                "deviceChannel" => "BROWSER",
                "referenceId" => $referenceId,
                "transactionMode" => "eCommerce"
            ]),
        ]);

        try {
            $result = $this->client->payService(
                $pamentData,
                $consumerData,
                $merchantAuthentication,
                $service
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
                    'service' => $service
                ],
            ];
        }
    }

    public function makePaymentIntent(Payments $payment): PaymentResponse | array
    {
        if ($payment->status === PaymentStatusEnum::PAID->value) {
            return [
                'status' => 'success',
                'message' => 'Payment already paid',
            ];
        }

        if ($payment->status === PaymentStatusEnum::FAILED->value) {
            return [
                'status' => 'error',
                'message' => 'Payment failed',
            ];
        }

        $this->payment = $payment;
        try {
            $payerData = $this->startPaymentIntent($payment->order, $payment);
            $consumerAuthentication = $payerData['consumerAuthenticationInformation'];
            $referenceId = $consumerAuthentication['referenceId'];
            $enrollmentData = $this->checkEnrollment($payment->order, $referenceId);
            if ($enrollmentData['status'] === 'AUTHENTICATION_SUCCESSFUL') {
                $consumerData = ConsumerAuthentication::from($enrollmentData['consumerAuthenticationInformation']);

                $paymentResponse = $this->processPayment($payment, $consumerData, $referenceId);

                //  If the payment is successful and the status is PAYED
                if ($paymentResponse['status'] === 'success' && $paymentResponse['data']->status->name === 'PAYED') {
                    $payment->status = PaymentStatusEnum::PAID;
                    $payment->addMetadata([
                        'data' => $paymentResponse['data'],
                    ]);
                    $payment->save();
                    $payment->order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, $paymentResponse["data"]->id);
                    $payment->order->set(CustomFieldEnum::ECHO_PAY_TRANSACTION_ID->value, $paymentResponse["data"]->transactionId);
                    $payment->order->checkPayments();

                    return [
                        'status' => 'success',
                        'message' => 'Payment successful',
                        'data' => $paymentResponse['data'],
                    ];

                    //  If by the enrollment status the payment is supossed to pass but we miss some data it will fail
                } elseif ($paymentResponse['status'] === 'error') {
                    $payment->status = PaymentStatusEnum::FAILED;
                    $payment->addMetadata([
                        'enrollment_data' => $enrollmentData,
                    ]);
                    $payment->save();

                    $payment->order->updateQuietly([
                        'status' => OrderStatusEnum::FAILED->value,
                    ]);

                    $payment->order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($paymentResponse['message']));

                    return [
                        'status' => 'error',
                        'message' => $paymentResponse['message'],
                        'data' => $paymentResponse['data'],
                        "response" => $enrollmentData,
                    ];
                }
            } else {
                //  If the enrollment status is not AUTHENTICATION_SUCCESSFUL it means that the front needs to authenticate the payer
                $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION;
                $payment->addMetadata([
                    'enrollment_data' => $enrollmentData,
                ]);
                $payment->save();

                $payment->order->updateQuietly([
                    'status' => OrderStatusEnum::FAILED->value,
                ]);

                $payment->order->set(CustomFieldEnum::ECHO_PAY_PAYMENT_RESPONSE->value, json_encode($enrollmentData));
            }

            return [
                'status' => 'success',
                'message' => PaymentStatusEnum::PENDING_AUTHORIZATION,
                'data' => $enrollmentData
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
                'error' => $e->getMessage()
            ]);
            $payment->save();

            $payment->order->failed();

            return [
                'status' => 'error',
                'message' => $errorMessage,
                'response' => $e->getMessage(),
                'data' => $enrollmentData,
            ];
        }
    }
}
