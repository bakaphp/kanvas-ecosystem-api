<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\QuickBooks\Client;
use Kanvas\Connectors\QuickBooks\Enums\ConfigurationEnum;
use Kanvas\Connectors\QuickBooks\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use QuickBooksOnline\API\Data\IPPCreditMemo;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPLinkedTxn;
use QuickBooksOnline\API\Data\IPPPayment;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\Data\IPPSalesItemLineDetail;
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
     * Create a QuickBooks credit memo from a credit purchase Order
     * Credit memos represent customer credits that can be applied to future invoices
     */
    public function createDepositFromCreditOrder(Order $creditOrder): ?IPPCreditMemo
    {
        if ($creditOrder->get(CustomFieldEnum::QUICKBOOKS_DEPOSIT_ID->value)) {
            // Credit memo already exists, return existing
            return $this->getDepositByOrder($creditOrder);
        }

        // Validate amount
        if ($creditOrder->total_amount <= 0) {
            throw new Exception('Credit order amount must be greater than zero');
        }

        // Get or create customer (reuse from invoice service)
        $invoiceService = new QuickBooksInvoiceService($this->app);
        $customer = $invoiceService->getOrCreateCustomerFromCompany($creditOrder);

        if (! $customer) {
            throw new Exception('Failed to create or find customer for credit memo');
        }

        // Create credit memo for customer credits
        $creditMemo = new IPPCreditMemo();

        // Set customer reference
        $customerRef = new IPPReferenceType();
        $customerRef->value = $customer->Id;
        $customerRef->name = $customer->CompanyName ?: $customer->GivenName;
        $creditMemo->CustomerRef = $customerRef;

        // Set credit memo properties
        $creditMemo->DocNumber = 'CREDIT-' . $creditOrder->getOrderNumber();
        $creditMemo->TxnDate = $creditOrder->created_at->format('Y-m-d');
        $creditMemo->TotalAmt = (float) $creditOrder->total_amount;
        $creditMemo->PrivateNote = "Credit purchase from Kanvas Order #{$creditOrder->getOrderNumber()}";

        // Create line item for the credit - similar to invoice structure
        $lineAmount = (float) $creditOrder->total_amount;

        $line = new IPPLine();
        $line->LineNum = 1;
        $line->Amount = $lineAmount;
        $line->DetailType = 'SalesItemLineDetail';
        $line->Description = 'Customer Credit Purchase';

        // Create sales item line detail
        $salesItemLineDetail = new IPPSalesItemLineDetail();

        // Get or create a credit item
        $creditItem = $this->getOrCreateCreditItem();

        // Set item reference
        $itemRef = new IPPReferenceType();
        $itemRef->value = $creditItem->Id;
        $itemRef->name = $creditItem->Name;
        $salesItemLineDetail->ItemRef = $itemRef;

        $salesItemLineDetail->Qty = 1;
        $salesItemLineDetail->UnitPrice = $lineAmount;

        $line->SalesItemLineDetail = $salesItemLineDetail;
        $creditMemo->Line = [$line];

        // Create the credit memo in QuickBooks
        $resultingCreditMemo = $this->dataService->Add($creditMemo);
        $error = $this->dataService->getLastError();

        if ($error) {
            throw new Exception('QuickBooks Credit Memo Error: ' . $error->getResponseBody());
        }

        // Store QB credit memo ID in order metadata
        $creditOrder->set(CustomFieldEnum::QUICKBOOKS_DEPOSIT_ID->value, $resultingCreditMemo->Id);
        $creditOrder->set(CustomFieldEnum::QUICKBOOKS_DEPOSIT_AMOUNT->value, $resultingCreditMemo->TotalAmt);

        return $resultingCreditMemo;
    }

    /**
     * Apply customer credit memo to an invoice (for eSIM purchases)
     */
    public function applyDepositToInvoice(Order $esimOrder, ?float $amountToApply = null): bool
    {
        $invoiceId = $esimOrder->get(CustomFieldEnum::QUICKBOOKS_INVOICE_ID->value);

        if (! $invoiceId) {
            throw new Exception('No QuickBooks invoice found for eSIM order');
        }

        // Find available customer credit memos
        $customer = $this->getCustomerFromOrder($esimOrder);
        if (! $customer) {
            throw new Exception('Customer not found for credit application');
        }

        $availableCredits = $this->getAvailableCreditsForCustomer($customer->Id);

        if (empty($availableCredits)) {
            throw new Exception('No available credits found for customer');
        }

        $amountToApply = $amountToApply ?? $esimOrder->total_amount;

        return $this->applyCreditToInvoice($invoiceId, $availableCredits, $amountToApply, $customer);
    }

    /**
     * Get available credit memos for a customer
     */
    private function getAvailableCreditsForCustomer(string $customerId): array
    {
        try {
            // Get credit memos for the customer
            $creditMemos = $this->dataService->Query("SELECT * FROM CreditMemo WHERE CustomerRef = '{$customerId}'");

            // Filter credit memos that still have available balance
            $availableCredits = [];
            foreach ($creditMemos as $creditMemo) {
                $appliedAmount = $this->getAppliedCreditAmount($creditMemo->Id);
                $availableAmount = $creditMemo->TotalAmt - $appliedAmount;

                if ($availableAmount > 0) {
                    $creditMemo->AvailableAmount = $availableAmount;
                    $availableCredits[] = $creditMemo;
                }
            }

            return $availableCredits;
        } catch (Exception $e) {
            report($e);

            return [];
        }
    }

    /**
     * Get the amount already applied from a credit memo
     */
    private function getAppliedCreditAmount(string $creditMemoId): float
    {
        try {
            // Query for payments that reference this credit memo
            $payments = $this->dataService->Query('SELECT * FROM Payment');

            $appliedAmount = 0;
            foreach ($payments as $payment) {
                if (! $payment->Line) {
                    continue;
                }

                foreach ($payment->Line as $line) {
                    if (isset($line->LinkedTxn)) {
                        foreach ($line->LinkedTxn as $linkedTxn) {
                            if ($linkedTxn->TxnType === 'CreditMemo' && $linkedTxn->TxnId === $creditMemoId) {
                                $appliedAmount += $line->Amount;
                            }
                        }
                    }
                }
            }

            return $appliedAmount;
        } catch (Exception $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Apply credit memo to invoice by creating a payment
     */
    private function applyCreditToInvoice(string $invoiceId, array $credits, float $amountToApply, IPPCustomer $customer): bool
    {
        try {
            // For now, use the first available credit
            $creditMemo = $credits[0];

            if ($creditMemo->AvailableAmount < $amountToApply) {
                throw new Exception('Insufficient credit balance');
            }

            // Create a payment that applies the credit memo to the invoice
            $payment = new IPPPayment();

            // Set customer reference
            $customerRef = new IPPReferenceType();
            $customerRef->value = $customer->Id;
            $customerRef->name = $customer->CompanyName ?: $customer->GivenName;
            $payment->CustomerRef = $customerRef;

            $payment->TxnDate = date('Y-m-d');
            $payment->TotalAmt = $amountToApply;
            $payment->PrivateNote = 'Applied credit memo to invoice';

            // Create payment lines
            $lines = [];

            // Line 1: Apply to invoice (positive amount)
            $invoiceLine = new IPPLine();
            $invoiceLine->Amount = $amountToApply;

            $invoiceLinkedTxn = new IPPLinkedTxn();
            $invoiceLinkedTxn->TxnId = $invoiceId;
            $invoiceLinkedTxn->TxnType = 'Invoice';
            $invoiceLine->LinkedTxn = [$invoiceLinkedTxn];

            $lines[] = $invoiceLine;

            // Line 2: Use credit memo (negative amount)
            $creditLine = new IPPLine();
            $creditLine->Amount = -$amountToApply; // Negative because we're using the credit

            $creditLinkedTxn = new IPPLinkedTxn();
            $creditLinkedTxn->TxnId = $creditMemo->Id;
            $creditLinkedTxn->TxnType = 'CreditMemo';
            $creditLine->LinkedTxn = [$creditLinkedTxn];

            $lines[] = $creditLine;

            $payment->Line = $lines;

            // Create the payment
            $resultingPayment = $this->dataService->Add($payment);
            $error = $this->dataService->getLastError();

            if ($error) {
                throw new Exception('Failed to apply credit: ' . $error->getResponseBody());
            }

            return true;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }

    /**
     * Get or create credit item for credit memos
     */
    private function getOrCreateCreditItem()
    {
        try {
            // Try to find existing credit item
            $items = $this->dataService->Query("SELECT * FROM Item WHERE Name = 'Customer Credit' AND Active = true");

            if (! empty($items)) {
                return $items[0];
            }

            // Create new credit item
            $item = new \QuickBooksOnline\API\Data\IPPItem();
            $item->Name = 'Customer Credit';
            $item->Type = 'Service';
            $item->Active = true;

            // Set income account reference
            $incomeAccountRef = new IPPReferenceType();
            $incomeAccountRef->value = $this->getIncomeAccountId();
            $item->IncomeAccountRef = $incomeAccountRef;

            $result = $this->dataService->Add($item);
            $error = $this->dataService->getLastError();

            if ($error) {
                throw new Exception('Failed to create credit item: ' . $error->getResponseBody());
            }

            return $result;
        } catch (Exception $e) {
            report($e);

            // Return a fallback service item if credit item creation fails
            return $this->getFallbackServiceItem();
        }
    }

    /**
     * Get a fallback service item if credit item creation fails
     */
    private function getFallbackServiceItem()
    {
        try {
            $items = $this->dataService->Query("SELECT * FROM Item WHERE Type = 'Service' AND Active = true");

            if (! empty($items)) {
                return $items[0];
            }

            throw new Exception('No service items found in QuickBooks');
        } catch (Exception $e) {
            report($e);

            throw new Exception('Could not find any service items for credit memo');
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
     * Get credit memo by order
     */
    public function getDepositByOrder(Order $order): ?IPPCreditMemo
    {
        $creditMemoId = $order->get(CustomFieldEnum::QUICKBOOKS_DEPOSIT_ID->value);

        if (! $creditMemoId) {
            return null;
        }

        try {
            return $this->dataService->findById('CreditMemo', $creditMemoId);
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

        $credits = $this->getAvailableCreditsForCustomer($customer->Id);

        return array_sum(array_column($credits, 'AvailableAmount'));
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
    private function getIncomeAccountId(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_INCOME_ACCOUNT_ID->value) ?? '1';
    }
}
