<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Kanvas\Connectors\Mercury\DataTransferObject\MercuryCustomer;

/**
 * Mercury AR customers. Note the `/ar/` prefix — `/customers` is a different (nonexistent) route and 404s.
 */
class MercuryCustomerService extends MercuryApiService
{
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
