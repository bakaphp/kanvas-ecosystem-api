<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Models\CompaniesAddress;
use Kanvas\Connectors\QuickBooks\Client;
use Kanvas\Connectors\QuickBooks\Enums\ConfigurationEnum;
use Kanvas\Connectors\QuickBooks\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Souk\Orders\Models\Order;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPCustomerType;
use QuickBooksOnline\API\Data\IPPCustomField;
use QuickBooksOnline\API\Data\IPPEmailAddress;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPMemoRef;
use QuickBooksOnline\API\Data\IPPPhysicalAddress;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\Data\IPPSalesItemLineDetail;
use QuickBooksOnline\API\Data\IPPTelephoneNumber;
use QuickBooksOnline\API\DataService\DataService;

class QuickBooksInvoiceService
{
    private DataService $dataService;
    private AppInterface $app;

    public function __construct(AppInterface $app)
    {
        $this->app = $app;
        $this->dataService = new Client($app)->getDataService();
    }

    /**
     * Create a QuickBooks invoice from an Order
     */
    public function createInvoiceFromOrder(Order $order): ?IPPInvoice
    {
        if ($order->get(CustomFieldEnum::QUICKBOOKS_INVOICE_ID->value)) {
            // Invoice already exists, no need to create again
            return $this->getInvoiceByOrder($order);
        }

        // Get or create customer
        $customer = $this->getOrCreateCustomerFromCompany($order); //$this->getOrCreateCustomer($order);

        if (! $customer) {
            throw new Exception('Failed to create or find customer');
        }

        // Create invoice lines from order items
        $lines = $this->createInvoiceLines($order);

        // Create the invoice
        $invoice = new IPPInvoice();

        // Set customer reference
        $customerRef = new IPPReferenceType();
        $customerRef->value = $customer->Id;
        $customerRef->name = $customer->GivenName;
        $invoice->CustomerRef = $customerRef;

        // Set invoice properties
        $invoice->DocNumber = (string) $order->getOrderNumber();
        $invoice->TxnDate = $order->created_at->format('Y-m-d');
        $invoice->DueDate = $order->created_at->addDays(30)->format('Y-m-d');
        $invoice->Line = $lines;
        $invoice->PrivateNote = "Generated from Kanvas Order #{$order->getOrderNumber()}";

        // Set customer memo
        $customerMemo = new IPPMemoRef();
        $customerMemo->value = $order->customer_note ?? 'Thank you for your business!';
        $invoice->CustomerMemo = $customerMemo;

        // Add billing address if available
        if ($order->billingAddress) {
            $invoice->BillAddr = $this->formatAddress($order->billingAddress);
        }

        // Add shipping address if available and different from billing
        if ($order->shippingAddress && $order->shipping_address_id !== $order->billing_address_id) {
            $invoice->ShipAddr = $this->formatAddress($order->shippingAddress);
        }

        // Create the invoice in QuickBooks
        $resultingInvoice = $this->dataService->Add($invoice);
        $error = $this->dataService->getLastError();

        if ($error) {
            throw new Exception('QuickBooks Error: ' . $error->getResponseBody());
        }

        // Store QB invoice ID in order metadata
        $order->set(CustomFieldEnum::QUICKBOOKS_INVOICE_ID->value, $resultingInvoice->Id);
        $order->set(CustomFieldEnum::QUICKBOOKS_INVOICE_NUMBER->value, $resultingInvoice->DocNumber);

        return $resultingInvoice;
    }

    public function getOrCreateCustomerFromCompany(Order $order): ?IPPCustomer
    {
        $customerEmail = $order->company->email;
        $customerName = $order->company->name ?: 'Guest Customer';

        // First, try to find existing customer by email
        if ($customerEmail) {
            $customers = $this->dataService->Query("SELECT * FROM Customer WHERE PrimaryEmailAddr = '{$customerEmail}'");
            if (! empty($customers)) {
                return $customers[0];
            }
        }

        // Create new customer
        $customer = new IPPCustomer();
        $customer->GivenName = $customerName;
        $customer->CompanyName = $order->company->name;

        //@todo set customer type dynamically based on order type
        /*   $customerType = $this->getOrCreateCustomerType('B2B');

          if ($customerType) {
              $customerTypeRef = new IPPReferenceType();
              $customerTypeRef->value = $customerType->Id;
              $customerTypeRef->name = $customerType->Name;
              $customer->CustomerTypeRef = $customerTypeRef;
          } */

        // Set email
        if ($customerEmail) {
            $emailAddr = new IPPEmailAddress();
            $emailAddr->Address = $customerEmail;
            $customer->PrimaryEmailAddr = $emailAddr;
        }

        // Set phone
        $phone = $order->getPhone();
        if ($phone) {
            $phoneNumber = new IPPTelephoneNumber();
            $phoneNumber->FreeFormNumber = $phone;
            $customer->PrimaryPhone = $phoneNumber;
        }

        // Add billing address if available
        if ($order->company->addresses()->first()) {
            $customer->BillAddr = $this->formatAddress($order->company->addresses()->first());
        }

        $resultingCustomer = $this->dataService->Add($customer);

        $this->setCustomerCustomFields($resultingCustomer, [
            'b2b' => 'true',
        ]);

        $order->people->set(CustomFieldEnum::QUICKBOOKS_CUSTOMER_ID->value, $resultingCustomer->Id);

        $error = $this->dataService->getLastError();

        if ($error) {
            report(new Exception('QuickBooks Customer Creation Failed: ' . $error->getResponseBody()));

            return null;
        }

        return $resultingCustomer;
    }

    /**
     * Get existing customer or create new one
     */
    private function getOrCreateCustomer(Order $order): ?IPPCustomer
    {
        $customerEmail = $order->getEmail();
        $customerName = $order->people ? $order->people->getName() : ($customerEmail ? explode('@', $customerEmail)[0] : 'Guest Customer');

        // First, try to find existing customer by email
        if ($customerEmail) {
            $customers = $this->dataService->Query("SELECT * FROM Customer WHERE PrimaryEmailAddr = '{$customerEmail}'");
            if (! empty($customers)) {
                return $customers[0];
            }
        }

        // Create new customer
        $customer = new IPPCustomer();
        $customer->GivenName = $customerName;
        $customer->CompanyName = $order->people?->organizations?->first()?->name;

        // Set email
        if ($customerEmail) {
            $emailAddr = new IPPEmailAddress();
            $emailAddr->Address = $customerEmail;
            $customer->PrimaryEmailAddr = $emailAddr;
        }

        // Set phone
        $phone = $order->getPhone();
        if ($phone) {
            $phoneNumber = new IPPTelephoneNumber();
            $phoneNumber->FreeFormNumber = $phone;
            $customer->PrimaryPhone = $phoneNumber;
        }

        // Add billing address if available
        if ($order->billingAddress) {
            $customer->BillAddr = $this->formatAddress($order->billingAddress);
        }

        $resultingCustomer = $this->dataService->Add($customer);

        $this->setCustomerCustomFields($resultingCustomer, [
            'b2b' => 'true',
        ]);

        $order->people->set(CustomFieldEnum::QUICKBOOKS_CUSTOMER_ID->value, $resultingCustomer->Id);

        $error = $this->dataService->getLastError();

        if ($error) {
            report(new Exception('QuickBooks Customer Creation Failed: ' . $error->getResponseBody()));

            return null;
        }

        return $resultingCustomer;
    }

    private function getOrCreateCustomerType(string $typeName): ?IPPCustomerType
    {
        // First, try to find existing customer type
        $escapedTypeName = str_replace("'", "\'", $typeName);
        $customerTypes = $this->dataService->Query("SELECT * FROM CustomerType WHERE Name = '{$escapedTypeName}'");

        if (! empty($customerTypes)) {
            return $customerTypes[0];
        }

        // Create new customer type if it doesn't exist
        return $this->createCustomerType($typeName);
    }

    /**
     * Create a new customer type
     */
    private function createCustomerType(string $name): ?IPPCustomerType
    {
        $customerType = new IPPCustomerType();
        $customerType->Name = $name;
        $customerType->Active = true;

        $result = $this->dataService->Add($customerType);
        $error = $this->dataService->getLastError();

        return $result;
    }

    /**
     * Create invoice lines from order items
     */
    private function createInvoiceLines(Order $order): array
    {
        $lines = [];
        $lineNumber = 1;

        foreach ($order->items as $item) {
            // Get or create item in QuickBooks
            $qbItem = $this->getOrCreateItem($item);

            if (! $qbItem) {
                // Fallback to service item if product item creation fails
                $qbItem = $this->getDefaultServiceItem();
            }

            $line = new IPPLine();
            $line->LineNum = $lineNumber;
            $line->Amount = $item->quantity * $item->unit_price_gross_amount;
            $line->DetailType = 'SalesItemLineDetail';
            $line->Description = $item->product_name . ($item->variant_name ? ' - ' . $item->variant_name : '');

            // Create sales item line detail
            $salesItemLineDetail = new IPPSalesItemLineDetail();

            // Set item reference
            $itemRef = new IPPReferenceType();
            $itemRef->value = $qbItem->Id;
            $itemRef->name = $qbItem->Name;
            $salesItemLineDetail->ItemRef = $itemRef;

            $salesItemLineDetail->Qty = $item->quantity;
            $salesItemLineDetail->UnitPrice = $item->unit_price_gross_amount;

            // Set tax code if needed
            $taxCodeRef = new IPPReferenceType();
            $taxCodeRef->value = $this->getTaxCodeId($item->tax_rate ?? 0);
            $salesItemLineDetail->TaxCodeRef = $taxCodeRef;

            $line->SalesItemLineDetail = $salesItemLineDetail;

            $lines[] = $line;
            $lineNumber++;
        }

        // Add shipping line if applicable
        if ($order->shipping_price_gross_amount > 0) {
            $shippingItem = $this->getShippingItem();

            $line = new IPPLine();
            $line->LineNum = $lineNumber;
            $line->Amount = $order->shipping_price_gross_amount;
            $line->DetailType = 'SalesItemLineDetail';
            $line->Description = $order->shipping_method_name ?? 'Shipping';

            $salesItemLineDetail = new IPPSalesItemLineDetail();

            $itemRef = new IPPReferenceType();
            $itemRef->value = $shippingItem->Id;
            $itemRef->name = $shippingItem->Name;
            $salesItemLineDetail->ItemRef = $itemRef;

            $salesItemLineDetail->Qty = 1;
            $salesItemLineDetail->UnitPrice = $order->shipping_price_gross_amount;

            $line->SalesItemLineDetail = $salesItemLineDetail;
            $lines[] = $line;
            $lineNumber++;
        }

        // Add discount line if applicable
        if ($order->discount_amount > 0) {
            $discountItem = $this->getDiscountItem();

            $line = new IPPLine();
            $line->LineNum = $lineNumber;
            $line->Amount = -$order->discount_amount; // Negative for discount
            $line->DetailType = 'SalesItemLineDetail';
            $line->Description = $order->discount_name ?? 'Discount';

            $salesItemLineDetail = new IPPSalesItemLineDetail();

            $itemRef = new IPPReferenceType();
            $itemRef->value = $discountItem->Id;
            $itemRef->name = $discountItem->Name;
            $salesItemLineDetail->ItemRef = $itemRef;

            $salesItemLineDetail->Qty = 1;
            $salesItemLineDetail->UnitPrice = -$order->discount_amount;

            $line->SalesItemLineDetail = $salesItemLineDetail;
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Get the item type to use for new items
     */
    private function getItemType(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_DEFAULT_ITEM_TYPE->value) ?? 'Service';
    }

    /**
     * Get or create QuickBooks item for order item
     */
    private function getOrCreateItem($orderItem): ?IPPItem
    {
        // Try to find existing item by SKU
        $escapedSku = str_replace("'", "\'", $orderItem->product_sku);
        $items = $this->dataService->Query("SELECT * FROM Item WHERE Sku = '{$escapedSku}'");

        if (! empty($items)) {
            return $items[0];
        }

        // Create new item - try Inventory first, fallback to Service
        return $this->createItemWithFallback($orderItem);
    }

    /**
     * Create item with fallback from Inventory to Service type
     */
    private function createItemWithFallback($orderItem): ?IPPItem
    {
        // First attempt: Create as Inventory item
        $item = $this->createInventoryItem($orderItem);
        if ($item) {
            return $item;
        }

        // Fallback: Create as Service item
        report('Failed to create Inventory item, falling back to Service item');

        return $this->createServiceItem($orderItem);
    }

    /**
     * Create inventory item
     */
    private function createInventoryItem($orderItem): ?IPPItem
    {
        try {
            $item = new IPPItem();
            $item->Name = $orderItem->product_name;
            $item->Sku = $orderItem->product_sku;
            $item->Type = 'Inventory';
            $item->UnitPrice = $orderItem->unit_price_gross_amount;
            $item->TrackQtyOnHand = true;
            $item->QtyOnHand = 0;
            $item->InvStartDate = date('Y-m-d');

            // Set account references
            $incomeAccountRef = new IPPReferenceType();
            $incomeAccountRef->value = $this->getIncomeAccountId();
            $item->IncomeAccountRef = $incomeAccountRef;

            $assetAccountRef = new IPPReferenceType();
            $assetAccountRef->value = $this->getInventoryAssetAccountId();
            $item->AssetAccountRef = $assetAccountRef;

            $expenseAccountRef = new IPPReferenceType();
            $expenseAccountRef->value = $this->getCOGSAccountId();
            $item->ExpenseAccountRef = $expenseAccountRef;

            $resultingItem = $this->dataService->Add($item);
            $error = $this->dataService->getLastError();

            if ($error) {
                logger()->warning('QuickBooks Inventory Item Creation Failed', [
                    'sku' => $orderItem->product_sku,
                    'error' => $error->getResponseBody(),
                ]);

                return null;
            }

            return $resultingItem;
        } catch (Exception $e) {
            logger()->warning('QuickBooks Inventory Item Creation Exception', [
                'sku' => $orderItem->product_sku,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create service item (fallback)
     */
    private function createServiceItem($orderItem): ?IPPItem
    {
        try {
            $item = new IPPItem();
            $item->Name = $orderItem->product_name;
            $item->Sku = $orderItem->product_sku;
            $item->Type = $this->getItemType(); // Default to Service type
            $item->UnitPrice = $orderItem->unit_price_gross_amount;
            // Note: Service items don't need TrackQtyOnHand, AssetAccountRef, or ExpenseAccountRef

            // Set income account reference
            $incomeAccountRef = new IPPReferenceType();
            $incomeAccountRef->value = $this->getIncomeAccountId();
            $item->IncomeAccountRef = $incomeAccountRef;

            $resultingItem = $this->dataService->Add($item);
            $error = $this->dataService->getLastError();

            if ($error) {
                logger()->error('QuickBooks Service Item Creation Failed', [
                    'sku' => $orderItem->product_sku,
                    'error' => $error->getResponseBody(),
                ]);

                return null;
            }

            return $resultingItem;
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Set custom fields on customer using key-value pairs
     */
    private function setCustomerCustomFields(IPPCustomer $customer, array $fields): void
    {
        $customFieldObjects = [];

        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $customField = new IPPCustomField();
                $customField->Name = $key;
                $customField->StringValue = (string) $value;
                $customFieldObjects[] = $customField;
            }
        }

        if (! empty($customFieldObjects)) {
            $customer->CustomField = $customFieldObjects;
        }
    }

    /**
     * Format address for QuickBooks
     */
    private function formatAddress(CompaniesAddress|Address $address): IPPPhysicalAddress
    {
        $qbAddress = new IPPPhysicalAddress();
        $qbAddress->Line1 = $address->address;
        $qbAddress->Line2 = $address->address_2;
        $qbAddress->City = $address->city;
        $qbAddress->Country = $address->country?->name;
        $qbAddress->CountrySubDivisionCode = $address->state;
        $qbAddress->PostalCode = $address->zip;

        return $qbAddress;
    }

    /**
     * Get default service item (fallback)
     */
    private function getDefaultServiceItem(): IPPItem
    {
        $items = $this->dataService->Query("SELECT * FROM Item WHERE Type = 'Service' AND Active = true");

        if (! empty($items)) {
            return $items[0];
        }

        // Create a default service item if none exists
        $item = new IPPItem();
        $item->Name = 'General Service';
        $item->Type = 'Service';

        $incomeAccountRef = new IPPReferenceType();
        $incomeAccountRef->value = $this->getIncomeAccountId();
        $item->IncomeAccountRef = $incomeAccountRef;

        return $this->dataService->Add($item);
    }

    /**
     * Get or create shipping item
     */
    private function getShippingItem(): IPPItem
    {
        $items = $this->dataService->Query("SELECT * FROM Item WHERE Name = 'Shipping'");

        if (! empty($items)) {
            return $items[0];
        }

        $item = new IPPItem();
        $item->Name = 'Shipping';
        $item->Type = 'Service';

        $incomeAccountRef = new IPPReferenceType();
        $incomeAccountRef->value = $this->getShippingIncomeAccountId();
        $item->IncomeAccountRef = $incomeAccountRef;

        return $this->dataService->Add($item);
    }

    /**
     * Get or create discount item
     */
    private function getDiscountItem(): IPPItem
    {
        $items = $this->dataService->Query("SELECT * FROM Item WHERE Name = 'Discount'");

        if (! empty($items)) {
            return $items[0];
        }

        $item = new IPPItem();
        $item->Name = 'Discount';
        $item->Type = 'Service';

        $incomeAccountRef = new IPPReferenceType();
        $incomeAccountRef->value = $this->getDiscountAccountId();
        $item->IncomeAccountRef = $incomeAccountRef;

        return $this->dataService->Add($item);
    }

    /**
     * Helper methods to get account IDs from app configuration
     */
    private function getIncomeAccountId(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_INCOME_ACCOUNT_ID->value) ?? '1';
    }

    private function getInventoryAssetAccountId(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_INVENTORY_ASSET_ACCOUNT_ID->value) ?? '2';
    }

    private function getCOGSAccountId(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_COGS_ACCOUNT_ID->value) ?? '3';
    }

    private function getShippingIncomeAccountId(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_SHIPPING_INCOME_ACCOUNT_ID->value) ?? '4';
    }

    private function getDiscountAccountId(): string
    {
        return $this->app->get(ConfigurationEnum::QUICKBOOKS_DISCOUNT_ACCOUNT_ID->value) ?? '5';
    }

    private function getTaxCodeId(float $taxRate): string
    {
        // Map tax rates to QuickBooks tax codes
        if ($taxRate > 0) {
            return $this->app->get(ConfigurationEnum::QUICKBOOKS_TAXABLE_CODE->value) ?? 'TAX';
        }

        return $this->app->get(ConfigurationEnum::QUICKBOOKS_NON_TAXABLE_CODE->value) ?? 'NON';
    }

    /**
     * Update invoice status based on order status
     */
    public function updateInvoiceStatus(Order $order): bool
    {
        $invoiceId = $order->get(CustomFieldEnum::QUICKBOOKS_INVOICE_ID->value);

        if (! $invoiceId) {
            return false;
        }

        try {
            $invoice = $this->dataService->findById('Invoice', $invoiceId);

            if (! $invoice) {
                return false;
            }

            // Update based on order status
            if ($order->isCompleted()) {
                $currentNote = $invoice->PrivateNote ?? '';
                $invoice->PrivateNote = $currentNote . "\nOrder completed on " . now()->format('Y-m-d H:i:s');
            }

            $updatedInvoice = $this->dataService->Update($invoice);
            $error = $this->dataService->getLastError();

            if ($error) {
                report(new Exception('QuickBooks Invoice Update Failed: ' . $error->getResponseBody()));

                return false;
            }

            return true;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }

    /**
     * Get invoice by order
     */
    public function getInvoiceByOrder(Order $order): ?IPPInvoice
    {
        $invoiceId = $order->get(CustomFieldEnum::QUICKBOOKS_INVOICE_ID->value);

        if (! $invoiceId) {
            return null;
        }

        try {
            return $this->dataService->findById('Invoice', $invoiceId);
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Send invoice via email
     */
    public function sendInvoice(Order $order, ?string $emailAddress = null): bool
    {
        $invoice = $this->getInvoiceByOrder($order);

        if (! $invoice) {
            return false;
        }

        try {
            $email = $emailAddress ?? $order->getEmail();

            if (! $email) {
                report(new Exception('No email address available for sending invoice'));

                return false;
            }

            // Use QuickBooks send invoice API
            $this->dataService->SendEmail($invoice, $email);
            $error = $this->dataService->getLastError();

            if ($error) {
                report(new Exception('QuickBooks Send Invoice Failed: ' . $error->getResponseBody()));

                return false;
            }

            return true;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }
}
