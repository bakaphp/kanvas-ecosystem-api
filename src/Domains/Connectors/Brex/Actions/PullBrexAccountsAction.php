<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Brex\DataTransferObject\BrexAccount;
use Kanvas\Connectors\Brex\Services\BrexAccountService;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;

/**
 * Mirrors every Brex cash account, plus one row for the card program, into `accounting.bank_accounts`.
 * Idempotent on (source='brex', external_id). An existing row is never re-pointed at a different GL account:
 * a tenant's manual correction is a decision this sync has no business overriding.
 */
class PullBrexAccountsAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?UserInterface $user = null,
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    /**
     * @return list<BankAccount>
     */
    public function execute(): array
    {
        $service = new BrexAccountService($this->app, $this->company);

        $brexAccounts = $service->cashAccounts();
        $card = $service->cardAccount();

        if ($card !== null) {
            $brexAccounts[] = $card;
        }

        return array_map(fn (BrexAccount $account): BankAccount => $this->syncOne($account), $brexAccounts);
    }

    private function syncOne(BrexAccount $brexAccount): BankAccount
    {
        $existing = BankAccount::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('source', 'brex')
            ->where('external_id', $brexAccount->id)
            ->first();

        if ($existing === null) {
            $existing = new CreateBankAccountAction(
                data: new BankAccountData(
                    app: $this->app,
                    company: $this->company,
                    account_name: $brexAccount->name,
                    gl_account_id: $this->accountResolver->bySubType(
                        $this->app,
                        $this->company,
                        $brexAccount->cashAccountSubType(),
                    )->id,
                    currency: BrexAccount::CURRENCY,
                    account_number_last4: $brexAccount->last4(),
                    institution_name: 'Brex',
                    source: 'brex',
                    external_id: $brexAccount->id,
                ),
                user: $this->user,
            )->execute();
        }

        $existing->current_balance_native = $brexAccount->currentBalance;
        $existing->available_balance_native = $brexAccount->availableBalance;
        $existing->last_balance_sync_at = Carbon::now();
        $existing->last_synced_at = Carbon::now();
        $existing->save();

        return $existing;
    }
}
