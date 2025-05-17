<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthenticationData;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthenticationInformationData;
use Kanvas\Connectors\EchoPay\DataTransferObject\DeviceInformationData;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\OrderInformationData;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentDetailData;
use Kanvas\Connectors\EchoPay\DataTransferObject\PaymentResponseData;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\DataTransferObject\Order;


class AuthorizeNetPaymentProcessor
{
    protected Companies $company;
    protected EchoPayService $client;
    protected string $refId;

    /**
     * @psalm-suppress UndefinedMagicPropertyFetch
     * @psalm-suppress MixedAssignment
     */
    public function __construct(
        protected Apps $app,
        protected CompaniesBranches $branch
    ) {
        $this->company = $this->branch->company;
        $this->client = new EchoPayService($this->app, $this->company);
        $this->refId = 'ref' . time();        // Set the transaction's refId
    }

    protected function setupMerchantAuthentication(): MerchantDetailData
    {
        return MerchantDetailData::from([
            'id' => $this->app->get('ECHO_PAY_MERCHANT_ID'),
            'key' => $this->app->get('ECHO_PAY_MERCHANT_KEY'),
            'secretKey' => $this->app->get('ECHO_PAY_MERCHANT_SECRET')
        ]);
    }

    protected function setupPayerAuthentication(MerchantDetailData $merchantAuthentication, string $paymentInstrumentId): array
    {
     
        return $this->client->setupPayer($this->refId, $paymentInstrumentId, $merchantAuthentication);

    }

    protected function setCustomerBillingAddress(Order $orderInput): BillingDetailData
    {
        return new BillingDetailData(
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
            $orderInput->paymentMethod->stripe_card_id, 
            $merchantAuthentication
        );

        return $payerAuthentication;
    }

    public function checkEnrollment(Order $orderInput, string $referenceId): array
    {
        $merchantAuthentication = $this->setupMerchantAuthentication();
        $enrollmentCheck = $this->client->checkPayerEnrollment(
            PaymentDetailData::from([
                'orderCode' => $orderInput->reference . '_' . $orderInput->id,
                'paymentInstrumentId' => $orderInput->paymentMethod->stripe_card_id,
                'orderInformation' => OrderInformationData::from([
                    'currency' => 'DOP',
                    'totalAmount' => $orderInput->total,
                    'billTo' => $this->setCustomerBillingAddress($orderInput),
                ]),
                'deviceInformation' => DeviceInformationData::from([
                    "httpAcceptContent" => "application/json",
                    "httpBrowserLanguage" => "en_us",
                    "userAgentBrowserValue" => "chrome"
                ]),
                'consumerAuthenticationInformation' => ConsumerAuthenticationInformationData::from([
                    "deviceChannel" => "BROWSER",
                    "returnUrl" => "http://localhost:3000/return-url.js",
                    "referenceId" => $referenceId,
                    "transactionMode" => "eCommerce"
                ]),
            ]), 
            $merchantAuthentication);

        return $enrollmentCheck;
    }

    public function processPayment(Order $orderInput, ConsumerAuthenticationData $consumerAuthenticationData, $referenceId): PaymentResponseData
    {
        $merchantAuthentication = $this->setupMerchantAuthentication();
        $service = $this->setupService();
        $result = $this->client->payService(
            PaymentDetailData::from([
                'orderCode' => $orderInput->reference . '_' . $orderInput->id,
                'paymentInstrumentId' => $orderInput->paymentMethod->stripe_card_id,
                'orderInformation' => OrderInformationData::from([
                    'currency' => 'DOP',
                    'totalAmount' => $orderInput->total,
                    'billTo' => $this->setCustomerBillingAddress($orderInput),
                ]),
                'deviceInformation' => DeviceInformationData::from([
                    "httpAcceptContent" => "application/json",
                    "httpBrowserLanguage" => "en_us",
                    "userAgentBrowserValue" => "chrome"
                ]),
                'consumerAuthenticationInformation' => ConsumerAuthenticationInformationData::from([
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

        return $result;
    }
    
}
