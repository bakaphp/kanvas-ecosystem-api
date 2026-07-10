<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportAccount;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Scribe\Ledger\Actions\CreateAccountAction;
use Kanvas\Scribe\Ledger\DataTransferObject\Account as AccountData;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Users\Models\Users;
use RuntimeException;

/**
 * Pull the Acumatica GL chart of accounts (dbo.Account) into Scribe. Create-if-missing keyed on
 * external_id — chart of accounts is stable master data, so re-runs skip rather than mutate
 * (avoids fighting UpdateAccountAction's type-change / system-account guards).
 */
class PullChartOfAccountsAction
{
    /** @var array<string, int> per-run skip breakdown */
    public array $skipped = [];

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
    ) {
    }

    public function execute(): int
    {
        return $this->processRows($this->fetchRows());
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchRows(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('Account')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->select(['AccountID', 'AccountCD', 'Description', 'Type'])
            ->orderBy('AccountCD');

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     */
    public function processRows(array $rows): int
    {
        $count = 0;
        $this->skipped = [
            'unmapped_type' => 0,
            'no_account_number' => 0,
            'already_exists' => 0,
        ];

        foreach ($rows as $row) {
            $account = AcumaticaImportAccount::fromRow($row);

            if ($account->accountNumber === '') {
                $this->skipped['no_account_number']++;

                continue;
            }

            if ($account->accountType === null) {
                $this->skipped['unmapped_type']++;

                continue;
            }

            if ($this->findExisting($account->externalId, $account->accountNumber) !== null) {
                $this->skipped['already_exists']++;

                continue;
            }

            try {
                new CreateAccountAction(
                    new AccountData(
                        app: $this->app,
                        company: $this->company,
                        account_number: $account->accountNumber,
                        name: $account->name,
                        account_type: $account->accountType,
                        description: $account->description,
                        source: AcumaticaImportAccount::SOURCE,
                        external_id: $account->externalId !== '' ? $account->externalId : null,
                    ),
                    $this->user,
                )->execute();

                $count++;
            } catch (RuntimeException) {
                $this->skipped['already_exists']++;
            }
        }

        return $count;
    }

    private function findExisting(string $externalId, string $accountNumber): ?Account
    {
        $query = Account::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false);

        if ($externalId !== '') {
            $match = (clone $query)->where('external_id', $externalId)->first();

            if ($match !== null) {
                return $match;
            }
        }

        return $query->where('account_number', $accountNumber)->first();
    }
}
