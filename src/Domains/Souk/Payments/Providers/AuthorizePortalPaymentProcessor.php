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
use Kanvas\Connectors\EchoPay\Enums\MerchantCategoryEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantDocumentTypesEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantPlatformEnum;
use Kanvas\Connectors\EchoPay\Enums\MerchantTokenizationEnum;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

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
            country: $this->company->country,
            city: $this->company->city,
            address1: $this->company->address,
            phone: $orderInput->user->phone_number,
            email: $orderInput->user->email,
            postalCode: $this->company->zip,
            administrativeArea: $this->company->state,
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

    public function startPaymentIntent(Order $orderInput): array
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
                    "returnUrl" => "http://localhost:3000/portal/accept-code",
                    "referenceId" => $referenceId,
                    "transactionMode" => "eCommerce"
                ]),
            ]),
            $merchantAuthentication
        );

        return $enrollmentCheck;
    }

    public function processPayment(Payments $payment, ConsumerAuthentication $consumerAuthenticationData, $referenceId): PaymentResponse
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
                    "returnUrl" => "http://localhost:3000/portal/accept-code",
                    "referenceId" => $referenceId,
                    "transactionMode" => "eCommerce"

                ]),
            ]),
            $consumerAuthenticationData,
            $merchantAuthentication,
            $service
        );


        $this->checkEnrollment($payment->order, $referenceId);

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

        if ($payment->status === PaymentStatusEnum::PENDING->value) {
            return [
                'status' => 'pending',
                'message' => 'Payment pending',
            ];
        }


        $this->payment = $payment;
        $payerData = $this->startPaymentIntent($payment->order, $payment);
        $consumerAuthentication = $payerData['consumerAuthenticationInformation'];
        $referenceId = $consumerAuthentication['referenceId'];
        $enrollmentData = $this->checkEnrollment($payment->order, $referenceId);


        if ($enrollmentData['status'] === 'AUTHENTICATION_SUCCESSFUL') {
            $consumerAuthenticationData = ConsumerAuthentication::from($enrollmentData['consumerAuthenticationInformation']);
            $paymentResponse = $this->processPayment($payment, $consumerAuthenticationData, $referenceId);

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

            return $paymentResponse;
        }

        return $enrollmentData;
    }
}
