<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

class MercuryStatementService extends MercuryApiService
{
    /**
     * Monthly statements for one account. The heavy `transactions` array each statement carries is dropped —
     * we already hold every transaction as a first-class row, and keeping a second copy would be a second
     * source of truth.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAccount(string $mercuryAccountId): array
    {
        $response = $this->client->get("account/{$mercuryAccountId}/statements");

        return array_values(array_map(
            fn (array $statement): array => [
                'statement_id' => (string) ($statement['id'] ?? ''),
                'start_date' => (string) ($statement['startDate'] ?? ''),
                'end_date' => (string) ($statement['endDate'] ?? ''),
                'ending_balance' => (float) ($statement['endingBalance'] ?? 0),
                'download_url' => (string) ($statement['downloadUrl'] ?? ''),
            ],
            (array) ($response['statements'] ?? []),
        ));
    }
}
