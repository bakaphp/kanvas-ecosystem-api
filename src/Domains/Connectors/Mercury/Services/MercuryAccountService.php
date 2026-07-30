<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Kanvas\Connectors\Mercury\DataTransferObject\MercuryAccount;
use Throwable;

class MercuryAccountService extends MercuryApiService
{
    /**
     * Credit cards live behind a SEPARATE endpoint and never appear in `/accounts`, so pulling only that one
     * silently omits every card transaction. `isOurs()` drops counterparty rows — not our money.
     *
     * @return list<MercuryAccount>
     */
    public function list(): array
    {
        return array_values(array_filter(
            [...$this->depositAccounts(), ...$this->creditAccounts()],
            fn (MercuryAccount $account): bool => $account->isOurs(),
        ));
    }

    /**
     * @return list<MercuryAccount>
     */
    private function depositAccounts(): array
    {
        $response = $this->client->get('accounts');

        return array_values(array_map(
            fn (array $row): MercuryAccount => MercuryAccount::fromApi($row),
            (array) ($response['accounts'] ?? []),
        ));
    }

    /**
     * A tenant with no credit line 404s here rather than returning an empty list. That means "no card", not
     * "broken".
     *
     * @return list<MercuryAccount>
     */
    private function creditAccounts(): array
    {
        try {
            $response = $this->client->get('credit');
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(
            fn (array $row): MercuryAccount => MercuryAccount::fromCreditApi($row),
            (array) ($response['accounts'] ?? []),
        ));
    }
}
