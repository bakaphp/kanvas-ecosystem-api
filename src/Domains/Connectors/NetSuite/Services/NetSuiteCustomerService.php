<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Exceptions\ModelNotFoundException;
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
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    public function getCustomerById(int|string $customerId): Customer
    {
        $customerRef = new RecordRef();
        $customerRef->internalId = $customerId;
        $customerRef->type = 'customer';

        $getRequest = new GetRequest();
        $getRequest->baseRef = $customerRef;

        return $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($getRequest) {
            $response = $service->get($getRequest);

            if ($response->readResponse->status->isSuccess) {
                return $response->readResponse->record;
            }

            throw new ModelNotFoundException('Error retrieving customer: ' . $response->readResponse->status->statusDetail[0]->message);
        });
    }

    public function getInvoiceByNumber(string|int $invoiceNumber): array
    {
        $search = new TransactionSearch();
        $searchBasic = new TransactionSearchBasic();

        $searchBasic->tranId = new SearchStringField();
        $searchBasic->tranId->operator = 'is';
        $searchBasic->tranId->searchValue = $invoiceNumber;

        $search->basic = $searchBasic;

        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $search;

        // Execute the search with rate limiting
        $response = $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($searchRequest) {
            return $service->search($searchRequest);
        });

        if (! $response->searchResult->status->isSuccess) {
            throw new ModelNotFoundException('Error retrieving invoice: ' . $response->searchResult->status->statusDetail[0]->message);
        }

        // Prepare data for CSV
        $csvData = [];
        foreach ($response->searchResult->recordList->record as $invoice) {
            $getRequest = new GetRequest();
            $getRequest->baseRef = new RecordRef();
            $getRequest->baseRef->internalId = $invoice->internalId;
            $getRequest->baseRef->type = RecordType::invoice;

            // Get invoice details with rate limiting
            $transactionResponse = $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($getRequest) {
                return $service->get($getRequest);
            });

            if ($transactionResponse->readResponse->status->isSuccess) {
                $transactionDetail = $transactionResponse->readResponse->record;
                $invoiceDate = $transactionDetail->tranDate;
                $totalAmount = $transactionDetail->total;
                $customerName = $transactionDetail->entity->name ?? 'Unknown Customer';

                $csvData[] = ["Customer Name: $customerName"];
                $csvData[] = ["Invoice Number: $invoiceNumber"];
                $csvData[] = [];

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

                $itemSubtotal = 0;
                $itemCount = 0;

                if (! empty($transactionDetail->itemList->item)) {
                    foreach ($transactionDetail->itemList->item as $item) {
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
                            $item->item->name ?? 'N/A',
                            $customDescription,
                            $item->class->name ?? 'N/A',
                            $customValue,
                            $item->quantity ?? 0,
                            $item->rate ?? 0.00,
                            $item->amount ?? 0.00,
                        ];

                        $itemSubtotal += $item->amount ?? 0.00;
                        $itemCount += $item->quantity ?? 0;
                    }

                    $csvData[] = [
                        $invoiceNumber,
                        'Summary',
                        'Subtotal:',
                        '',
                        '',
                        '',
                        '',
                        $itemCount,
                        '',
                        $itemSubtotal,
                    ];
                } else {
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
