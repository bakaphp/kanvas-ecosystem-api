<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Services;

use Kanvas\Connectors\Brex\DataTransferObject\BrexAccount;

class BrexAccountService extends BrexApiService
{
    /**
     * @return list<BrexAccount>
     */
    public function cashAccounts(): array
    {
        return array_map(
            fn (array $row): BrexAccount => BrexAccount::fromCashApi($row),
            $this->client->getAllPages('v2/accounts/cash'),
        );
    }

    /**
     * Null when the company has no card program. `/v2/accounts/card` is a bare array, not a page.
     */
    public function cardAccount(): ?BrexAccount
    {
        $rows = array_values(array_map(
            fn (mixed $row): array => (array) $row,
            $this->client->get('v2/accounts/card'),
        ));

        return $rows === [] ? null : BrexAccount::fromCardApi($rows);
    }
}
