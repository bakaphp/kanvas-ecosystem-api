<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

use DateTime;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Orders\DataTransferObject\DirectOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum as TaxConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1\ANetApiResponseType;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class AuthorizeNetPaymentProcessor
{
    protected Companies $company;
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
        $this->refId = 'ref' . time();
    }

    protected function setupMerchantAuthentication(): AnetAPI\MerchantAuthenticationType
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($this->company->get('MERCHANT_LOGIN_ID'));
        $merchantAuthentication->setTransactionKey($this->company->get('MERCHANT_TRANSACTION_KEY'));

        return $merchantAuthentication;
    }

    protected function setCreditCard(CreditCard $creditCardData): AnetAPI\CreditCardType
    {
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($creditCardData->number);
        $creditCard->setExpirationDate($creditCardData->exp_month . '-' . $creditCardData->exp_year);
        $creditCard->setCardCode($creditCardData->cvv);

        return $creditCard;
    }

    protected function setOrder(DirectOrder $orderInput, ?OrderTaxSnapshot $snapshot = null): AnetAPI\OrderType
    {
        $order = new AnetAPI\OrderType();

        if ($snapshot?->merchant_reference !== null) {
            $order->setInvoiceNumber($snapshot->merchant_reference);
        } else {
            $order->setInvoiceNumber((string) (time() + $orderInput->user->getId()));
        }

        $order->setDescription((string) $orderInput->cart->getContent()->first()->name);

        return $order;
    }

    protected function setCustomerData(DirectOrder $orderInput): AnetAPI\CustomerDataType
    {
        $customerData = new AnetAPI\CustomerDataType();
        $customerData->setType('individual');
        $customerData->setId($orderInput->user->getId());
        $customerData->setEmail($orderInput->user->email);

        return $customerData;
    }

    protected function setCustomerBillingAddress(DirectOrder $orderInput): AnetAPI\CustomerAddressType
    {
        $customerAddress = new AnetAPI\CustomerAddressType();
        $customerAddress->setFirstName($orderInput->user->firstname);
        $customerAddress->setLastName($orderInput->user->lastname);
        $customerAddress->setCompany($orderInput->user->getId());

        $billingAddress = $orderInput->creditCard?->billing;
        if ($billingAddress !== null) {
            $customerAddress->setAddress($billingAddress->address);
            $customerAddress->setCity($billingAddress->city);
            $customerAddress->setState($billingAddress->state);
            $customerAddress->setZip($billingAddress->zip);
            $customerAddress->setCountry($billingAddress->country);
        }

        return $customerAddress;
    }

    public function processCreditCardPayment(DirectOrder $orderInput): ANetApiResponseType
    {
        $snapshot = $this->resolveSnapshot($orderInput);

        $merchantAuthentication = $this->setupMerchantAuthentication();

        $creditCard = $this->setCreditCard($orderInput->creditCard);
        $order = $this->setOrder($orderInput, $snapshot);

        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setCreditCard($creditCard);

        $customerData = $this->setCustomerData($orderInput);
        $customerAddress = $this->setCustomerBillingAddress($orderInput);

        $duplicateWindowSetting = new AnetAPI\SettingType();
        $duplicateWindowSetting->setSettingName('duplicateWindow');
        $duplicateWindowSetting->setSettingValue('60');

        $chargeAmount = $snapshot !== null
            ? (float) ($snapshot->checkout_payload['totals']['gateway_due'] ?? $orderInput->cart->getTotal())
            : $orderInput->cart->getTotal();

        if ($snapshot !== null) {
            $this->refId = substr($snapshot->gateway_attempt_key ?? $this->refId, 0, 20);
        }

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType('authCaptureTransaction');
        $transactionRequestType->setAmount($chargeAmount);
        $transactionRequestType->setPayment($paymentOne);
        $transactionRequestType->setCustomer($customerData);
        $transactionRequestType->setOrder($order);
        $transactionRequestType->setBillTo($customerAddress);
        $transactionRequestType->addToTransactionSettings($duplicateWindowSetting);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($this->refId);
        $request->setTransactionRequest($transactionRequestType);

        $controller = new AnetController\CreateTransactionController($request);

        $response = $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        );

        if ($snapshot !== null && $response->getMessages()->getResultCode() === 'Ok') {
            $transResponse = $response->getTransactionResponse();

            if ($transResponse !== null && $transResponse->getMessages() !== null) {
                $snapshot->payment_intent_id = $transResponse->getTransId();
                $snapshot->save();
            }
        }

        return $response;
    }

    private function resolveSnapshot(DirectOrder $orderInput): ?OrderTaxSnapshot
    {
        $taxEngineEnabled = (bool) $this->app->get(TaxConfigurationEnum::TAX_ENGINE_ENABLED->value);
        $cartSessionId = $orderInput->cart->getSessionKey();

        $snapshot = OrderTaxSnapshot::query()
            ->where('apps_id', $this->app->getId())
            ->where('cart_session_id', $cartSessionId)
            ->where('status', TaxStatusEnum::CALCULATED->value)
            ->whereNull('order_id')
            ->latest()
            ->first();

        if ($snapshot === null && $taxEngineEnabled) {
            throw ValidationException::withMessages(['tax' => 'tax_snapshot_required']);
        }

        return $snapshot;
    }

    public function processSubscriptionPayment(DirectOrder $orderInput, int $intervalLength = 30): ANetApiResponseType
    {
        $merchantAuthentication = $this->setupMerchantAuthentication();

        $firstItem = $orderInput->cart->getContent()->first();
        $variantAttributes = $firstItem->attributes;

        $subscription = new AnetAPI\ARBSubscriptionType();
        $subscription->setName($firstItem->name);

        $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
        $interval->setLength($intervalLength);
        $interval->setUnit('days');

        $paymentSchedule = new AnetAPI\PaymentScheduleType();
        $paymentSchedule->setInterval($interval);
        $paymentSchedule->setStartDate(new DateTime());
        $paymentSchedule->setTotalOccurrences(9999);
        $paymentSchedule->setTrialOccurrences(1);

        $subscription->setPaymentSchedule($paymentSchedule);

        $subscription->setAmount($variantAttributes['subscription_price'] ?? 0);
        $subscription->setTrialAmount($orderInput->cart->getTotal());

        $creditCard = $this->setCreditCard($orderInput->creditCard);

        $payment = new AnetAPI\PaymentType();
        $payment->setCreditCard($creditCard);
        $subscription->setPayment($payment);

        $order = $this->setOrder($orderInput);

        $subscription->setOrder($order);

        $billTo = new AnetAPI\NameAndAddressType();
        $billTo->setFirstName($orderInput->user->firstname);
        $billTo->setLastName($orderInput->user->lastname);
        $billTo->setCompany($orderInput->user->getId());

        $subscription->setBillTo($billTo);

        $request = new AnetAPI\ARBCreateSubscriptionRequest();
        $request->setmerchantAuthentication($merchantAuthentication);
        $request->setRefId($this->refId);
        $request->setSubscription($subscription);
        $controller = new AnetController\ARBCreateSubscriptionController($request);

        $subscriptionInitialCharge = null;

        if (isset($variantAttributes['subscription_initial_charge'])) {
            $subscriptionInitialCharge = $this->processCreditCardPayment($orderInput);
        }

        return $subscriptionInitialCharge === null ? $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        ) : $subscriptionInitialCharge;
    }
}
