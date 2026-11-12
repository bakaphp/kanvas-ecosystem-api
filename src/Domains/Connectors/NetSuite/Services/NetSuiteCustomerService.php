<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Exceptions\ModelNotFoundException;
use NetSuite\Classes\Customer;
use NetSuite\Classes\RecordType;

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
        return $this->client->getRecord(RecordType::customer, $customerId, 'customer');
    }

    public function getInvoiceByNumber(string|int $invoiceNumber): array
    {
        $invoiceService = new NetSuiteInvoiceService($this->app, $this->company);

        $csvData = [];
        foreach ($invoiceService->searchInvoiceIdsByNumber((string) $invoiceNumber) as $internalId) {
            try {
                $transactionDetail = $invoiceService->getInvoiceById($internalId);
            } catch (ModelNotFoundException) {
                continue;
            }

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

        return $csvData;
    }
}
