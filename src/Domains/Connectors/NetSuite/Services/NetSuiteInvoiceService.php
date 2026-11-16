<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Arr;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Exceptions\ModelNotFoundException;
use NetSuite\Classes\Invoice;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SearchEnumMultiSelectField;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\Classes\TransactionSearch;
use NetSuite\Classes\TransactionSearchBasic;
use NetSuite\Classes\TransactionType;
use NetSuite\NetSuiteService;

class NetSuiteInvoiceService
{
    protected Client $client;

    public function __construct(protected AppInterface $app, protected CompanyInterface $company)
    {
        $this->client = new Client($app, $company);
    }

    public function getInvoiceById(int|string $internalId): Invoice
    {
        return $this->client->getRecord(RecordType::invoice, $internalId, 'invoice');
    }

    public function getInvoiceByNumber(string $invoiceNumber): Invoice
    {
        $ids = $this->searchInvoiceIdsByNumber($invoiceNumber);

        if ($ids === []) {
            throw new ModelNotFoundException('NetSuite invoice ' . $invoiceNumber . ' not found');
        }

        return $this->getInvoiceById($ids[0]);
    }

    /**
     * Restricted to invoices: a sales order or quote can carry the same tranId.
     *
     * @return list<string>
     */
    public function searchInvoiceIdsByNumber(string $invoiceNumber): array
    {
        $basic = new TransactionSearchBasic();
        $basic->tranId = new SearchStringField();
        $basic->tranId->operator = 'is';
        $basic->tranId->searchValue = $invoiceNumber;
        $basic->type = new SearchEnumMultiSelectField();
        $basic->type->operator = 'anyOf';
        $basic->type->searchValue = [TransactionType::_invoice];

        $search = new TransactionSearch();
        $search->basic = $basic;

        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $search;

        $response = $this->client->executeWithRateLimit(
            fn (NetSuiteService $service) => $service->search($searchRequest)
        );

        if (! $response->searchResult->status->isSuccess) {
            throw new ModelNotFoundException('Error retrieving invoice: ' . $response->searchResult->status->statusDetail[0]->message);
        }

        return array_values(array_map(
            fn ($record): string => (string) $record->internalId,
            Arr::wrap($response->searchResult->recordList->record ?? null)
        ));
    }
}
