<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryAccount;
use Kanvas\Connectors\Mercury\Services\MercuryAccountService;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;

/**
 * Mirrors every Mercury deposit account into `accounting.bank_accounts` and refreshes its balances.
 *
 * Idempotent on (apps_id, source='mercury', external_id) — re-running updates balances rather than
 * duplicating the account.
 *
 * Each new bank account is pointed at the GL cash account matching Mercury's own `kind` — checking to
 * Cash — Checking, savings to Cash — Savings. We never RE-point an existing row on a later pull: doing so
 * would silently redirect where a bank's cash lands in the books, and any correction a tenant made by hand
 * is a deliberate decision we have no business overriding.
 */
class PullMercuryAccountsAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?UserInterface $user = null,
        protected readonly ?MercuryAccountService $accountService = null,
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    /**
     * @return list<BankAccount>
     */
    public function execute(): array
    {
        $service = $this->accountService ?? new MercuryAccountService($this->app, $this->company);

        $synced = [];
        foreach ($service->list() as $mercuryAccount) {
            $synced[] = $this->syncOne($mercuryAccount);
        }

        return $synced;
    }

    private function syncOne(MercuryAccount $mercuryAccount): BankAccount
    {
        $existing = BankAccount::query()
            ->where('apps_id', $this->app->getId())
            ->where('source', 'mercury')
            ->where('external_id', $mercuryAccount->id)
            ->first();

        if ($existing === null) {
            $existing = new CreateBankAccountAction(
                data: new BankAccountData(
                    app: $this->app,
                    company: $this->company,
                    account_name: $mercuryAccount->displayName(),
                    gl_account_id: $this->accountResolver->bySubType(
                        $this->app,
                        $this->company,
                        $mercuryAccount->cashAccountSubType(),
                    )->id,
                    currency: MercuryAccount::CURRENCY,
                    account_number_last4: $mercuryAccount->last4(),
                    institution_name: 'Mercury',
                    source: 'mercury',
                    external_id: $mercuryAccount->id,
                ),
                user: $this->user,
            )->execute();
        }

        $existing->current_balance_native = $mercuryAccount->currentBalance;
        $existing->available_balance_native = $mercuryAccount->availableBalance;
        $existing->last_balance_sync_at = Carbon::now();
        $existing->last_synced_at = Carbon::now();
        $existing->save();

        return $existing;
    }
}
