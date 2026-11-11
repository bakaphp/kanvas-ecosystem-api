<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Services;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Brex\DataTransferObject\BrexTransaction;

class BrexTransactionService extends BrexApiService
{
    /**
     * @return list<BrexTransaction>
     */
    public function cashTransactions(string $cashAccountId, Carbon $postedSince): array
    {
        $rows = $this->client->getAllPages(
            'v2/transactions/cash/' . rawurlencode($cashAccountId),
            ['posted_at_start' => $this->rfc3339($postedSince)],
        );

        return array_map(fn (array $row): BrexTransaction => BrexTransaction::fromCashApi($row), $rows);
    }

    /**
     * Settled transactions across every card account; Brex has no per-card-account transaction endpoint.
     *
     * @return list<BrexTransaction>
     */
    public function cardTransactions(Carbon $postedSince): array
    {
        $rows = $this->client->getAllPages(
            'v2/transactions/card/primary',
            ['posted_at_start' => $this->rfc3339($postedSince)],
        );

        return array_map(fn (array $row): BrexTransaction => BrexTransaction::fromCardApi($row), $rows);
    }

    private function rfc3339(Carbon $date): string
    {
        return $date->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
