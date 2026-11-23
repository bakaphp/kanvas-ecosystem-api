<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Client;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SalesOrder;

class NetSuiteSalesOrderService
{
    protected Client $client;

    public function __construct(protected AppInterface $app, protected CompanyInterface $company)
    {
        $this->client = new Client($app, $company);
    }

    public function getSalesOrderById(int|string $internalId): SalesOrder
    {
        return $this->client->getRecord(RecordType::salesOrder, $internalId, 'sales order');
    }
}
