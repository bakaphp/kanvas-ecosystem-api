<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\QuickBooks\Client;
use Kanvas\Connectors\QuickBooks\Enums\ConfigurationEnum;
use Kanvas\Connectors\QuickBooks\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPLinkedTxn;
use QuickBooksOnline\API\Data\IPPPayment;
use QuickBooksOnline\API\Data\IPPPaymentMethod;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\DataService\DataService;

class QuickBooksDepositService
{
    private DataService $dataService;
    private AppInterface $app;

    public function __construct(AppInterface $app)
    {
        $this->app = $app;
        $this->dataService = new Client($app)->getDataService();
    }

    /**
     * Create a QuickBooks customer payment (unapplied) from a credit purchase Order
     */
    public function createDepositFromCreditOrder(Order $creditOrder): ?IPPPayment
    {
        if ($creditOrder->get(CustomFieldEnum::QUICKBOOKS_DEPOSIT_ID->value)) {
            // Payment already exists, return existing
            return $this->getDepositByOrder($creditOrder);
        }

        // Get or create customer (reuse from invoice service)
        $invoiceService = new QuickBooksInvoiceService($this->app);
        $customer = $invoiceService->getOrCreateCustomerFromCompany($creditOrder);

        if (! $customer) {
            throw new Exception('Failed to create or find customer for deposit');
        }

        // Create an unapplied payment (customer deposit)
        $payment = new IPPPayment();

        // Set customer reference
        $customerRef = new IPPReferenceType();
        $customerRef->value = $customer->Id;
        $customerRef->name = $customer->CompanyName ?: $customer->GivenName;
        $payment->CustomerRef = $customerRef;

        // Set payment properties
        $payment->TxnDate = $creditOrder->created_at->format('Y-m-d');
        $payment->TotalAmt = $creditOrder->total_amount;
        $payment->PrivateNote = "Credit purchase from Kanvas Order #{$creditOrder->getOrderNumber()}";

        // Set payment method if available
        $paymentMethod = $this->getOrCreatePaymentMethod($creditOrder->payment_method ?? 'Credit Card');
        if ($paymentMethod) {
            $paymentMethodRef = new IPPReferenceType();
            $paymentMethodRef->value = $paymentMethod->Id;
            $paymentMethodRef->name = $paymentMethod->Name;
            $payment->PaymentMethodRef = $paymentMethodRef;
        }

        // Set deposit to account (Undeposited Funds or direct to bank)
        $depositAccountRef = new IPPReferenceType();
        $depositAccountRef->value = $this->getUndepositedFundsAccountId();
        $payment->DepositToAccountRef = $depositAccountRef;

        // Leave Line empty for unapplied payment (customer credit)
        // When this payment is applied to an invoice later, the line will be created
        $payment->Line = [];

        // Create the payment in QuickBooks
        $resultingPayment = $this->dataService->Add($payment);
        $error = $this->dataService->getLastError();

        if ($error) {
            throw new Exception('QuickBooks Payment Error: ' . $error->getResponseBody());
        }

        // Store QB payment ID in order metadata
        $creditOrder->set(CustomFieldEnum::QUICKBOOKS_DEPOSIT_ID->value, $resultingPayment->Id);
        $creditOrder->set(CustomFieldEnum::QUICKBOOKS_DEPOSIT_AMOUNT->value, $resultingPayment->TotalAmt);

        return $resultingPayment;
    }

    /**
     * Apply customer payment to an invoice (for eSIM purchases)
     */
    public function applyDepositToInvoice(Order $esimOrder, ?float $amountToApply = null): bool
    {
        $invoiceId = $esimOrder->get(CustomFieldEnum::QUICKBOOKS_INVOICE_ID->value);

        if (! $invoiceId) {
            throw new Exception('No QuickBooks invoice found for eSIM order');
        }

        // Find available customer payments
        $customer = $this->getCustomerFromOrder($esimOrder);
        if (! $customer) {
            throw new Exception('Customer not found for payment application');
        }

        $availablePayments = $this->getAvailableDepositsForCustomer($customer->Id);

        if (empty($availablePayments)) {
            throw new Exception('No available payments found for customer');
        }

        $amountToApply = $amountToApply ?? $esimOrder->total_amount;

        return $this->applyPaymentToInvoice($invoiceId, $availablePayments, $amountToApply);
    }

    /**
     * Get available payments (customer credits) for a customer
     */
    private function getAvailableDepositsForCustomer(string $customerId): array
    {
        try {
            // Get unapplied payments for the customer
            $payments = $this->dataService->Query("SELECT * FROM Payment WHERE CustomerRef = '{$customerId}'");

            // Filter payments that still have available balance (unapplied amount)
            $availablePayments = [];
            foreach ($payments as $payment) {
                $appliedAmount = $this->getAppliedPaymentAmount($payment->Id);
                $availableAmount = $payment->TotalAmt - $appliedAmount;

                if ($availableAmount > 0) {
                    $payment->AvailableAmount = $availableAmount;
                    $availablePayments[] = $payment;
                }
            }

            return $availablePayments;
        } catch (Exception $e) {
            report($e);

            return [];
        }
    }

    /**
     * Get the amount already applied from a payment
     */
    private function getAppliedPaymentAmount(string $paymentId): float
    {
        try {
            // Get the payment details to see applied lines
            $payment = $this->dataService->findById('Payment', $paymentId);

            if (! $payment || ! $payment->Line) {
                return 0;
            }

            $appliedAmount = 0;
            foreach ($payment->Line as $line) {
                if (isset($line->Amount)) {
                    $appliedAmount += $line->Amount;
                }
            }

            return $appliedAmount;
        } catch (Exception $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Apply payment to invoice by updating the payment with invoice line
     */
    private function applyPaymentToInvoice(string $invoiceId, array $payments, float $amountToApply): bool
    {
        try {
            // For now, use the first available payment
            // In a more complex scenario, you might want to use multiple payments
            $payment = $payments[0];

            if ($payment->AvailableAmount < $amountToApply) {
                throw new Exception('Insufficient payment balance');
            }

            // Get the payment to update
            $existingPayment = $this->dataService->findById('Payment', $payment->Id);

            if (! $existingPayment) {
                throw new Exception('Payment not found for update');
            }

            // Create payment line to link to invoice
            $line = new IPPLine();
            $line->Amount = $amountToApply;

            // Create linked transaction reference
            $linkedTxn = new IPPLinkedTxn();
            $linkedTxn->TxnId = $invoiceId;
            $linkedTxn->TxnType = 'Invoice';

            // Set LinkedTxn on the line
            $line->LinkedTxn = [$linkedTxn];

            // Add the line to the existing payment
            $existingLines = $existingPayment->Line ?? [];
            $existingLines[] = $line;
            $existingPayment->Line = $existingLines;

            // Mark as sparse update
            $existingPayment->sparse = true;

            // Update the payment
            $updatedPayment = $this->dataService->Update($existingPayment);
            $error = $this->dataService->getLastError();

            if ($error) {
                throw new Exception('Failed to apply payment: ' . $error->getResponseBody());
            }

            return true;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }

    /**
     * Get or create payment method
     */
    private function getOrCreatePaymentMethod(string $methodName): ?IPPPaymentMethod
    {
        try {
            $escapedMethodName = str_replace("'", "\'", $methodName);
            $paymentMethods = $this->dataService->Query("SELECT * FROM PaymentMethod WHERE Name = '{$escapedMethodName}'");

            if (! empty($paymentMethods)) {
                return $paymentMethods[0];
            }

            // Create new payment method
            $paymentMethod = new IPPPaymentMethod();
            $paymentMethod->Name = $methodName;
            $paymentMethod->Active = true;

            $result = $this->dataService->Add($paymentMethod);
            $error = $this->dataService->getLastError();

            if ($error) {
                logger()->warning('Failed to create payment method', [
                    'method' => $methodName,
                    'error' => $error->getResponseBody(),
                ]);

                return null;
            }

            return $result;
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get customer from order
     */
    private function getCustomerFromOrder(Order $order): ?IPPCustomer
    {
        $customerId = $order->people->get(CustomFieldEnum::QUICKBOOKS_CUSTOMER_ID->value);

        if (! $customerId) {
            return null;
        }

        try {
            return $this->dataService->findById('Customer', $customerId);
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get payment by order
     */
    public function getDepositByOrder(Order $order): ?IPPPayment
    {
        $paymentId = $order->get(CustomFieldEnum::QUICKBOOKS_DEPOSIT_ID->value);

        if (! $paymentId) {
            return null;
        }

        try {
            return $this->dataService->findById('Payment', $paymentId);
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get customer's total available credit balance
     */
    public function getCustomerCreditBalance(Order $order): float
    {
        $customer = $this->getCustomerFromOrder($order);

        if (! $customer) {
            return 0;
        }

        $deposits = $this->getAvailableDepositsForCustomer($customer->Id);

        return array_sum(array_column($deposits, 'AvailableAmount'));
    }

    /**
     * Check if customer has sufficient credit for order
     */
    public function hasInsufficientCredit(Order $order): bool
    {
        $availableCredit = $this->getCustomerCreditBalance($order);

        return $availableCredit < $order->total_amount;
    }

    /**
     * Helper methods to get account IDs from app configuration
     */
    private function getUndepositedFundsAccountId(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_UNDEPOSITED_FUNDS_ACCOUNT_ID->value) ?? '4'; // Undeposited Funds account
    }
}
