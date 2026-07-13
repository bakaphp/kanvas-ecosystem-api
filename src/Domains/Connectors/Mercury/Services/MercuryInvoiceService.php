<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Kanvas\Connectors\Mercury\DataTransferObject\MercuryInvoice;

/**
 * Mercury AR invoices. Note the `/ar/` prefix — `/invoices` is a different (nonexistent) route and 404s.
 */
class MercuryInvoiceService extends MercuryApiService
{
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

    /**
     * Cancel is a POST, not a DELETE — `DELETE /ar/invoices/{id}` answers 405 despite what the reference
     * implies. Only unpaid invoices are eligible, and Mercury will not undo it.
     */
    public function cancel(string $mercuryInvoiceId): void
    {
        $this->client->post("ar/invoices/{$mercuryInvoiceId}/cancel", []);
    }
}
