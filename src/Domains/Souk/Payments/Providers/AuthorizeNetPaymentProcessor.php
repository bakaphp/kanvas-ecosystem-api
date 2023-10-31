<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Providers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Orders\DataTransferObject\Order;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1\ANetApiResponseType;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class AuthorizeNetPaymentProcessor
{
    protected Companies $company;

    /**
     * @psalm-suppress UndefinedMagicPropertyFetch
     * @psalm-suppress MixedAssignment
     */
    public function __construct(
        protected Apps $app,
        protected CompaniesBranches $branch
    ) {
        $this->company = $this->branch->company;
    }

    public function processCreditCardPayment(Order $orderInput): ANetApiResponseType
    {
        /* Create a merchantAuthenticationType object with authentication details
             retrieved from the constants file */
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($this->company->get('MERCHANT_LOGIN_ID'));
        $merchantAuthentication->setTransactionKey($this->company->get('MERCHANT_TRANSACTION_KEY'));

        // Set the transaction's refId
        $refId = 'ref' . time();

        // Create the payment data for a credit card
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($orderInput->creditCard->number);
        $creditCard->setExpirationDate($orderInput->creditCard->exp_month . '-' . $orderInput->creditCard->exp_year);
        $creditCard->setCardCode($orderInput->creditCard->cvv);

        // Add the payment data to a paymentType object
        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setCreditCard($creditCard);

        // Set the customer's identifying information
        $customerData = new AnetAPI\CustomerDataType();

        $customerData->setType('individual');
        $customerData->setId($orderInput->user->getId());
        $customerData->setEmail($orderInput->user->email);

        // Add values for transaction settings
        $duplicateWindowSetting = new AnetAPI\SettingType();
        $duplicateWindowSetting->setSettingName('duplicateWindow');
        $duplicateWindowSetting->setSettingValue('60');

        // Add some merchant defined fields. These fields won't be stored with the transaction,
        // but will be echoed back in the response.
        /*         $merchantDefinedField1 = new AnetAPI\UserFieldType();
                $merchantDefinedField1->setName('customerLoyaltyNum');
                $merchantDefinedField1->setValue('1128836273');

                $merchantDefinedField2 = new AnetAPI\UserFieldType();
                $merchantDefinedField2->setName('favoriteColor2');
                $merchantDefinedField2->setValue('blue2'); */

        // Create a TransactionRequestType object and add the previous objects to it
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType('authCaptureTransaction');
        $transactionRequestType->setAmount($orderInput->cart->getTotal()); //$orderInput->cart->getTotal());
        $transactionRequestType->setPayment($paymentOne);
        $transactionRequestType->setCustomer($customerData);
        $transactionRequestType->addToTransactionSettings($duplicateWindowSetting);
        //$transactionRequestType->addToUserFields($merchantDefinedField1);
        //$transactionRequestType->addToUserFields($merchantDefinedField2);

        // Assemble the complete transaction request
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest($transactionRequestType);

        // Create the controller and get the response
        $controller = new AnetController\CreateTransactionController($request);

        return $controller->executeWithApiResponse(
            $this->company->get('MERCHANT_PRODUCTION') ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX
        );
    }
}
