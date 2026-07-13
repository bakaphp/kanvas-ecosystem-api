<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Kanvas\Connectors\Mercury\DataTransferObject\MercuryAccount;
use Throwable;

class MercuryAccountService extends MercuryApiService
{
    /**
     * Every Mercury account we own — deposit accounts AND credit cards.
     *
     * The credit card lives behind a SEPARATE endpoint (`/credit`) and does not appear in `/accounts` at
     * all. Pulling only `/accounts` silently omits every card transaction, which for a company that puts its
     * SaaS spend on the card is most of the operating expense. Two calls, one list.
     *
     * Counterparty rows (type=external/recipient) are filtered out — they are not our money and must never
     * become bank accounts on the books.
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
     * A tenant with no credit line gets a 404 here rather than an empty list. That's not an error — it just
     * means no card — so swallow it instead of failing the whole account pull.
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
