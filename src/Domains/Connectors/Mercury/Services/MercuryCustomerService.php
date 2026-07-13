<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mercury\Client;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryCustomer;

/**
 * Mercury AR customers. Note the `/ar/` prefix — `/customers` is a different (nonexistent) route and 404s.
 */
class MercuryCustomerService
{
    protected Client $client;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        ?Client $client = null,
    ) {
        $this->client = $client ?? new Client($this->app, $this->company);
    }

    /**
     * @return list<MercuryCustomer>
     */
    public function list(): array
    {
        $response = $this->client->get('ar/customers');

        return array_values(array_map(
            fn (array $row): MercuryCustomer => MercuryCustomer::fromApi($row),
            (array) ($response['customers'] ?? []),
        ));
    }

    public function create(MercuryCustomer $customer): MercuryCustomer
    {
        return MercuryCustomer::fromApi(
            $this->client->post('ar/customers', $customer->toApiPayload()),
        );
    }
}
