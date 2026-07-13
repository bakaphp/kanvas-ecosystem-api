<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mercury\Client;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryInvoice;

/**
 * Mercury AR invoices. Note the `/ar/` prefix — `/invoices` is a different (nonexistent) route and 404s.
 */
class MercuryInvoiceService
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
     * @return list<MercuryInvoice>
     */
    public function list(): array
    {
        $response = $this->client->get('ar/invoices');

        return array_values(array_map(
            fn (array $row): MercuryInvoice => MercuryInvoice::fromApi($row),
            (array) ($response['invoices'] ?? []),
        ));
    }

    /**
     * @param array<string, mixed> $payload Built by MercuryInvoice::payloadFromInvoice.
     */
    public function create(array $payload): MercuryInvoice
    {
        return MercuryInvoice::fromApi(
            $this->client->post('ar/invoices', $payload),
        );
    }
}
