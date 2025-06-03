<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

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
use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Throwable;

class AuthorizePortalPaymentProcessor
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
                    cardIdentifier: $this->app->get('ECHO_PAY_MERCHANT_IDENTIFIER'),
                    platform: MerchantPlatformEnum::WEB,
                    customerId: "user_" . $this->payment->order->user->id,
                    tokenization: MerchantTokenizationEnum::TOKENIZATION_YES,
                    documentType: MerchantDocumentTypesEnum::DNI,
                    documentNumber: $this->app->get('ECHO_PAY_MERCHANT_DOCUMENT_NUMBER'),
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

    protected function setupService(): array
    {
        return [
            "merchantKey" => "00000000016739100006575",
            "channelCode" => "004",
            "serviceCode" => "0101",
            "serviceTypeId" => "106",
            "contract" => "6537824"
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

    public function processPayment(Payments $payment, ConsumerAuthentication $consumerData, $referenceId): PaymentResponse
    {
        $merchantAuthentication = $this->setupMerchantAuthentication(includeDetails: true);
        $service = $this->setupService();
        $result = $this->client->payService(
            PaymentDetail::from([
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
            ]),
            $consumerData,
            $merchantAuthentication,
            $service
        );

        return $result;
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
        $payerData = $this->startPaymentIntent($payment->order, $payment);
        $consumerAuthentication = $payerData['consumerAuthenticationInformation'];
        $referenceId = $consumerAuthentication['referenceId'];
        $enrollmentData = $this->checkEnrollment($payment->order, $referenceId);

        try {
            if ($enrollmentData['status'] === 'AUTHENTICATION_SUCCESSFUL') {
                $consumerData = ConsumerAuthentication::from($enrollmentData['consumerAuthenticationInformation']);

                $paymentResponse = $this->processPayment($payment, $consumerData, $referenceId);

                if ($paymentResponse->status->name === 'PAYED') {
                    $payment->status = PaymentStatusEnum::PAID;
                    $payment->addMetadata([
                        'data' => $paymentResponse->toArray(),
                    ]);
                    $payment->save();
                    $payment->order->addPrivateMetadata('payment_intent_id', $paymentResponse->id);
                    $payment->order->addPrivateMetadata('payment_transaction_id', $paymentResponse->transactionId);
                    $payment->order->checkPayments();
                }

                return [
                    'status' => 'success',
                    'message' => 'Payment successful',
                    'data' => $paymentResponse,
                ];
            } else {
                $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION;
                $payment->addPrivateMetadata('enrollment_data', $enrollmentData);
                $payment->save();
            }
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Payment failed',
                'response' => $e->getMessage(),
                'data' => $enrollmentData,
            ];
        }

        return [
            'status' => 'success',
            'message' => PaymentStatusEnum::PENDING_AUTHORIZATION,
            'data' => $enrollmentData
        ];
    }
}
