<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use NetSuite\Classes\Customer;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\Classes\TransactionSearch;
use NetSuite\Classes\TransactionSearchBasic;
use NetSuite\NetSuiteService;

class NetSuiteCustomerService
{
    protected NetSuiteService $service;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->service = (new Client($app, $company))->getService();
    }

    public function getCustomerById(int|string $customerId): Customer
    {
        $customerRef = new RecordRef();
        $customerRef->internalId = $customerId;
        $customerRef->type = 'customer'; // Add the record type here

        $getRequest = new GetRequest();
        $getRequest->baseRef = $customerRef;

        $response = $this->service->get($getRequest);

        if ($response->readResponse->status->isSuccess) {
            return $response->readResponse->record;
        } else {
            throw new Exception('Error retrieving customer: ' . $response->readResponse->status->statusDetail[0]->message);
        }
    }

    public function getInvoiceByNumber(string|int $invoiceNumber): array
    {
        $search = new TransactionSearch();
        $searchBasic = new TransactionSearchBasic();

        // Add filter for Invoice Number (tranId)
        $searchBasic->tranId = new SearchStringField();
        $searchBasic->tranId->operator = 'is'; // Exact match
        $searchBasic->tranId->searchValue = $invoiceNumber;

        $search->basic = $searchBasic;

        // Wrap the TransactionSearch in a SearchRequest
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $search;

        // Execute the search
        $response = $this->service->search($searchRequest);

        if (! $response->searchResult->status->isSuccess) {
            throw new Exception('Error retrieving invoice: ' . $response->searchResult->status->statusDetail[0]->message);
        }

        // Prepare data for CSV
        $csvData = [];
        foreach ($response->searchResult->recordList->record as $invoice) {
            $getRequest = new GetRequest();
            $getRequest->baseRef = new RecordRef();
            $getRequest->baseRef->internalId = $invoice->internalId;
            $getRequest->baseRef->type = RecordType::invoice;

            $transactionResponse = $this->service->get($getRequest);

            if ($transactionResponse->readResponse->status->isSuccess) {
                $transactionDetail = $transactionResponse->readResponse->record;
                $invoiceDate = $transactionDetail->tranDate;
                $totalAmount = $transactionDetail->total;
                $customerName = $transactionDetail->entity->name ?? 'Unknown Customer';

                // Add a header with customer name and invoice numberq
                $csvData[] = ["Customer Name: $customerName"];
                $csvData[] = ["Invoice Number: $invoiceNumber"];
                $csvData[] = []; // Empty row for spacing

                // Add the column headers
                $csvData[] = [
                    'Invoice Number',
                    'Date',
                    'Total Amount',
                    'Item Name',
                    'Item Description',
                    'Class',
                    'Custom Field',
                    'Quantity',
                    'Rate',
                    'Amount',
                ];

                $itemSubtotal = 0; // Initialize subtotal for the invoice
                $itemCount = 0;    // Count items in the invoice

                if (! empty($transactionDetail->itemList->item)) {
                    foreach ($transactionDetail->itemList->item as $item) {
                        // Extract custom fields
                        $customDescription = 'N/A';
                        $customValue = 'N/A';

                        if (! empty($item->customFieldList->customField)) {
                            foreach ($item->customFieldList->customField as $customField) {
                                if ($customField->scriptId === 'custcol_item_description') {
                                    $customDescription = $customField->value;
                                }
                                if ($customField->scriptId === 'custcol3') {
                                    $customValue = $customField->value;
                                }
                            }
                        }

                        $csvData[] = [
                            $invoiceNumber,
                            $invoiceDate,
                            $totalAmount,
                            $item->item->name ?? 'N/A',        // Item Name
                            $customDescription,               // Item Description from custom field
                            $item->class->name ?? 'N/A',      // Class
                            $customValue,                     // Additional custom field value
                            $item->quantity ?? 0,             // Quantity
                            $item->rate ?? 0.00,              // Rate
                            $item->amount ?? 0.00,             // Amount
                        ];

                        // Accumulate totals
                        $itemSubtotal += $item->amount ?? 0.00;
                        $itemCount += $item->quantity ?? 0;
                    }

                    // Add a summary row for the invoice
                    $csvData[] = [
                        $invoiceNumber,
                        'Summary',
                        'Subtotal:',
                        '',
                        '',
                        '',
                        '',
                        $itemCount, // Total Quantity
                        '',
                        $itemSubtotal, // Total Amount for the invoice
                    ];
                } else {
                    // No items case
                    $csvData[] = [
                        $invoiceNumber,
                        $invoiceDate,
                        $totalAmount,
                        'No Items',
                        '',
                        '',
                        '',
                        0,
                        0.00,
                        0.00,
                    ];
                }
            }
        }

        return $csvData;
    }
}
