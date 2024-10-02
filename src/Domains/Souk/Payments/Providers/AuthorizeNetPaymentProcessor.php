<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

use DateTime;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Orders\DataTransferObject\DirectOrder;
use Kanvas\Souk\Payments\DataTransferObject\CreditCard;
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
        $this->refId = 'ref' . time();        // Set the transaction's refId
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

    protected function setOrder(DirectOrder $orderInput): AnetAPI\OrderType
    {
        $order = new AnetAPI\OrderType();
        $order->setInvoiceNumber(time() + $orderInput->user->getId());
        $order->setDescription($orderInput->cart->getContent()->first()->name);

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
        /* Create a merchantAuthenticationType object with authentication details
             retrieved from the constants file */
        $merchantAuthentication = $this->setupMerchantAuthentication();

        // Create the payment data for a credit card
        $creditCard = $this->setCreditCard($orderInput->creditCard);
        $order = $this->setOrder($orderInput);

        // Add the payment data to a paymentType object
        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setCreditCard($creditCard);

        // Set the customer's identifying information
        $customerData = $this->setCustomerData($orderInput);
        $customerAddress = $this->setCustomerBillingAddress($orderInput);

        // Add values for transaction settings
        $duplicateWindowSetting = new AnetAPI\SettingType();
        $duplicateWindowSetting->setSettingName('duplicateWindow');
        $duplicateWindowSetting->setSettingValue('60');

        // Create a TransactionRequestType object and add the previous objects to it
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType('authCaptureTransaction');
        $transactionRequestType->setAmount($orderInput->cart->getTotal()); //$orderInput->cart->getTotal());
        $transactionRequestType->setPayment($paymentOne);
        $transactionRequestType->setCustomer($customerData);
        $transactionRequestType->setOrder($order);
        $transactionRequestType->setBillTo($customerAddress);
        $transactionRequestType->addToTransactionSettings($duplicateWindowSetting);
        //$transactionRequestType->addToUserFields($merchantDefinedField1);
        //$transactionRequestType->addToUserFields($merchantDefinedField2);

        // Assemble the complete transaction request
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($this->refId);
        $request->setTransactionRequest($transactionRequestType);

        // Create the controller and get the response
        $controller = new AnetController\CreateTransactionController($request);

        return $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        );
    }

    /**
     * @todo move to its own class
     */
    public function processSubscriptionPayment(DirectOrder $orderInput, int $intervalLength = 30): ANetApiResponseType
    {
        /* Create a merchantAuthenticationType object with authentication details
       retrieved from the constants file */
        $merchantAuthentication = $this->setupMerchantAuthentication();

        $variantAttributes = $orderInput->cart->getContent()->first()->attributes;
        // Subscription Type Info
        $subscription = new AnetAPI\ARBSubscriptionType();
        $subscription->setName($orderInput->cart->getContent()->first()->name);

        $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
        $interval->setLength($intervalLength);
        $interval->setUnit('days');

        $paymentSchedule = new AnetAPI\PaymentScheduleType();
        $paymentSchedule->setInterval($interval);
        // today in date time
        $paymentSchedule->setStartDate(new DateTime());
        $paymentSchedule->setTotalOccurrences(9999);
        $paymentSchedule->setTrialOccurrences(1); // Set trial occurrences to 1 for the first payment

        $subscription->setPaymentSchedule($paymentSchedule);
        $subscription->setAmount($variantAttributes->get('subscription_price'));
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
        if ($variantAttributes->has('subscription_initial_charge')) {
            $subscriptionInitialCharge = $this->processCreditCardPayment($orderInput);
        }

        return $subscriptionInitialCharge === null ? $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        ) : $subscriptionInitialCharge;
    }

    /**
     * @todo move to its own class
     */
    public function createCustomerProfileWithPayment(DirectOrder $orderInput)
    {
        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->setupMerchantAuthentication();

        // Set credit card information for payment profile
        $creditCard = $this->setCreditCard($orderInput->creditCard);
        $paymentCreditCard = new AnetAPI\PaymentType();
        $paymentCreditCard->setCreditCard($creditCard);

        // Create the Bill To info for new payment type
        // Set the customer's identifying information
        $customerAddress = $this->setCustomerBillingAddress($orderInput);


        // Create a new CustomerPaymentProfile object
        $paymentProfile = new AnetAPI\CustomerPaymentProfileType();
        $paymentProfile->setCustomerType('individual');
        $paymentProfile->setBillTo($customerAddress);
        $paymentProfile->setPayment($paymentCreditCard);
        $paymentProfiles[] = $paymentProfile;

        // Create a new CustomerProfileType and add the payment profile object
        $customerProfile = new AnetAPI\CustomerProfileType();
        $customerProfile->setDescription($orderInput->cart->getContent()->first()->name);
        $customerProfile->setMerchantCustomerId($orderInput->user->getId());
        $customerProfile->setEmail($orderInput->user->email);
        $customerProfile->setpaymentProfiles($paymentProfiles);

        // Assemble the complete transaction request
        $request = new AnetAPI\CreateCustomerProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($this->refId);
        $request->setProfile($customerProfile);

        // Create the controller and get the response
        $controller = new AnetController\CreateTransactionController($request);

        return $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        );
    }

    public function createCustomerPaymentProfile(DirectOrder $orderInput)
    {
        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->setupMerchantAuthentication();

        // Set credit card information for payment profile
        $creditCard = $this->setCreditCard($orderInput->creditCard);
        $paymentCreditCard = new AnetAPI\PaymentType();
        $paymentCreditCard->setCreditCard($creditCard);

        // Create the Bill To info for new payment type
        // Set the customer's identifying information
        $customerAddress = $this->setCustomerBillingAddress($orderInput);

        // Create a new CustomerPaymentProfile object
        $paymentProfile = new AnetAPI\CustomerPaymentProfileType();
        $paymentProfile->setCustomerType('individual');
        $paymentProfile->setBillTo($customerAddress);
        $paymentProfile->setPayment($paymentCreditCard);
        $paymentProfiles[] = $paymentProfile;

        // Assemble the complete transaction request
        $paymentprofilerequest = new AnetAPI\CreateCustomerPaymentProfileRequest();
        $paymentprofilerequest->setMerchantAuthentication($merchantAuthentication);

        // Add an existing profile id to the request
        $paymentprofilerequest->setCustomerProfileId($orderInput->customerProfile->id);
        $paymentprofilerequest->setPaymentProfile($paymentProfile);
        $paymentprofilerequest->setValidationMode("liveMode");

        // Create the controller and get the response
        $controller = new AnetController\CreateCustomerPaymentProfileController($paymentprofilerequest);

        return $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        );
    }

    public function updateCustomerPaymentProfile(DirectOrder $orderInput)
    {
        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->setupMerchantAuthentication();

        $profile = new AnetAPI\GetCustomerPaymentProfileRequest();
        $profile->setMerchantAuthentication($merchantAuthentication);
        $profile->setRefId($this->refId);
        $profile->setCustomerProfileId($orderInput->customerProfile->id);
        $profile->setCustomerPaymentProfileId($orderInput->customerPaymentProfile->id);

        $profileController = new AnetController\GetCustomerPaymentProfileController($profile);

        $profileResponse = $profileController->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        );

        if (($profileResponse != null) && ($profileResponse->getMessages()->getResultCode() == "Ok")) {
            // Set credit card information for payment profile
            $creditCard = $this->setCreditCard($orderInput->creditCard);
            $paymentCreditCard = new AnetAPI\PaymentType();
            $paymentCreditCard->setCreditCard($creditCard);

            // Set the customer's identifying information
            $customerAddress = $this->setCustomerBillingAddress($orderInput);

            $paymentprofile = new AnetAPI\CustomerPaymentProfileExType();
            $paymentprofile->setBillTo($customerAddress);
            $paymentprofile->setCustomerPaymentProfileId($orderInput->customerPaymentProfile->id);
            $paymentprofile->setPayment($paymentCreditCard);

            // Submit a UpdatePaymentProfileRequest
            $request = new AnetAPI\UpdateCustomerPaymentProfileRequest();
            $request->setMerchantAuthentication($merchantAuthentication);
            $request->setCustomerProfileId($orderInput->customerProfile->id);
            $request->setPaymentProfile($paymentprofile);

            $controller = new AnetController\UpdateCustomerPaymentProfileController($request);

            return $controller->executeWithApiResponse(
                $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
            );
        } else {
            return $profileResponse;
        }
    }

    public function deleteCustomerPaymentProfile(DirectOrder $orderInput)
    {
        /* Create a merchantAuthenticationType object with authentication details
        retrieved from the constants file */
        $merchantAuthentication = $this->setupMerchantAuthentication();

        $profile = new AnetAPI\DeleteCustomerPaymentProfileRequest();
        $profile->setMerchantAuthentication($merchantAuthentication);
        $profile->setCustomerProfileId($orderInput->customerProfile->id);
        $profile->setCustomerPaymentProfileId($orderInput->customerPaymentProfile->id);

        $controller = new AnetController\DeleteCustomerPaymentProfileController($profile);

        return $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        );
    }
}
