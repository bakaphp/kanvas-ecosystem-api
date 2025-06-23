<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Services;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\QuickBooks\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use QuickBooksOnline\API\Data\IPPCustomer;
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
        $this->initializeDataService();
    }

    private function initializeDataService(): void
    {
        $clientId = $this->app->get(ConfigurationEnum::QUICKBOOKS_CLIENT_ID->value);
        $clientSecret = $this->app->get(ConfigurationEnum::QUICKBOOKS_CLIENT_SECRET->value);
        $accessToken = $this->app->get(ConfigurationEnum::QUICKBOOKS_ACCESS_TOKEN->value);
        $refreshToken = $this->app->get(ConfigurationEnum::QUICKBOOKS_REFRESH_TOKEN->value);
        $realmId = $this->app->get(ConfigurationEnum::QUICKBOOKS_REALM_ID->value);
        $baseUrl = $this->app->get(ConfigurationEnum::QUICKBOOKS_BASE_URL->value) ?? 'Production';

        if (empty($clientId) || empty($clientSecret) || empty($accessToken) || empty($realmId)) {
            throw new ValidationException('QuickBooks credentials are not properly configured for app: ' . $this->app->name);
        }

        $this->dataService = DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => $clientId,
            'ClientSecret' => $clientSecret,
            'accessTokenKey' => $accessToken,
            'refreshTokenKey' => $refreshToken,
            'QBORealmID' => $realmId,
            'baseUrl' => $baseUrl,
        ]);
    }

    /**
     * Create a QuickBooks invoice from an Order
     */
    public function createInvoiceFromOrder(Order $order): ?IPPInvoice
    {
        try {
            // Get or create customer
            $customer = $this->getOrCreateCustomer($order);

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
            $customerRef->name = $customer->Name;
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
            $order->addMetadata('quickbooks_invoice_id', $resultingInvoice->Id);
            $order->addMetadata('quickbooks_invoice_number', $resultingInvoice->DocNumber);

            return $resultingInvoice;
        } catch (Exception $e) {
            // Log the error
            logger()->error('QuickBooks Invoice Creation Failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
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
        $customer->Name = $customerName;
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
        $error = $this->dataService->getLastError();

        if ($error) {
            logger()->error('QuickBooks Customer Creation Failed', [
                'order_id' => $order->id,
                'error' => $error->getResponseBody(),
            ]);

            return null;
        }

        return $resultingCustomer;
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

        // Create new item
        $item = new IPPItem();
        $item->Name = $orderItem->product_name;
        $item->Sku = $orderItem->product_sku;
        $item->Type = 'Inventory'; // or "Service" based on your needs
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
            logger()->warning('QuickBooks Item Creation Failed', [
                'sku' => $orderItem->product_sku,
                'error' => $error->getResponseBody(),
            ]);

            return null;
        }

        return $resultingItem;
    }

    /**
     * Format address for QuickBooks
     */
    private function formatAddress($address): IPPPhysicalAddress
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
        $invoiceId = $order->getMetadata('quickbooks_invoice_id');

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
                logger()->error('QuickBooks Invoice Update Failed', [
                    'invoice_id' => $invoiceId,
                    'error' => $error->getResponseBody(),
                ]);

                return false;
            }

            return true;
        } catch (Exception $e) {
            logger()->error('QuickBooks Invoice Update Exception', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get invoice by order
     */
    public function getInvoiceByOrder(Order $order): ?IPPInvoice
    {
        $invoiceId = $order->getMetadata('quickbooks_invoice_id');

        if (! $invoiceId) {
            return null;
        }

        try {
            return $this->dataService->findById('Invoice', $invoiceId);
        } catch (Exception $e) {
            logger()->error('QuickBooks Get Invoice Failed', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

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
                logger()->warning('No email address available for sending invoice', [
                    'order_id' => $order->id,
                    'invoice_id' => $invoice->Id,
                ]);

                return false;
            }

            // Use QuickBooks send invoice API
            $this->dataService->SendEmail($invoice, $email);
            $error = $this->dataService->getLastError();

            if ($error) {
                logger()->error('QuickBooks Send Invoice Failed', [
                    'invoice_id' => $invoice->Id,
                    'email' => $email,
                    'error' => $error->getResponseBody(),
                ]);

                return false;
            }

            return true;
        } catch (Exception $e) {
            logger()->error('QuickBooks Send Invoice Exception', [
                'invoice_id' => $invoice->Id ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
