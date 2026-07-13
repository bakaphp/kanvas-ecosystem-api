<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryAccount;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryTransaction;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mercury\Services\MercuryTransactionService;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use RuntimeException;

/**
 * Pulls settled Mercury transactions for one bank account into `accounting.bank_transactions`.
 *
 * This action INGESTS ONLY — it deliberately posts no journal entries. Whether a transaction gets its own
 * JE depends on whether it settles an existing bill or invoice, and that's the matcher's call (PR 3).
 * Posting here would send every incoming payment to Suspense before anything had a chance to match it.
 *
 * Incremental: the cursor is the postedAt of the newest transaction we've ingested for this account, minus
 * a re-check window. The overlap is intentional — Mercury can post a transaction with an earlier postedAt
 * than one we've already seen, and a strict "newer than the cursor" filter would skip it forever.
 * Re-ingesting is free because CreateBankTransactionAction is idempotent on external_id.
 */
class PullMercuryTransactionsAction
{
    /** How far back to re-scan on every run, to catch late-posting transactions. */
    private const int RECHECK_WINDOW_DAYS = 3;

    /** First-ever pull for an account: how much history to bring in. */
    public const int DEFAULT_LOOKBACK_DAYS = 90;

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly BankAccount $bankAccount,
        public readonly ?UserInterface $user = null,
        /** Only applies to the first pull; afterwards the stored cursor takes over. */
        public readonly int $initialLookbackDays = self::DEFAULT_LOOKBACK_DAYS,
        protected readonly ?MercuryTransactionService $transactionService = null,
    ) {
    }

    /**
     * @return list<BankTransaction>
     */
    public function execute(): array
    {
        $mercuryAccountId = $this->bankAccount->external_id;

        if ($mercuryAccountId === null || $this->bankAccount->source !== 'mercury') {
            throw new RuntimeException(
                "BankAccount {$this->bankAccount->getId()} is not a Mercury account — refusing to sync."
            );
        }

        $service = $this->transactionService
            ?? new MercuryTransactionService($this->app, $this->company);

        $transactions = $service->listForAccount($mercuryAccountId, $this->resolveCursor($mercuryAccountId));

        $landed = [];
        $newestPostedAt = null;

        foreach ($transactions as $transaction) {
            $landed[] = $this->land($transaction);

            if ($newestPostedAt === null || $transaction->postedAt->greaterThan($newestPostedAt)) {
                $newestPostedAt = $transaction->postedAt;
            }
        }

        if ($newestPostedAt !== null) {
            $this->company->set(
                ConfigurationEnum::SYNC_CURSOR->forAccount($mercuryAccountId),
                $newestPostedAt->toIso8601String(),
            );
        }

        $this->bankAccount->last_synced_at = Carbon::now();
        $this->bankAccount->save();

        return $landed;
    }

    private function land(MercuryTransaction $transaction): BankTransaction
    {
        return new CreateBankTransactionAction(
            data: new BankTransactionData(
                app: $this->app,
                company: $this->company,
                bankAccount: $this->bankAccount,
                postedAt: $transaction->postedAt,
                transactionDate: $transaction->postedAt->copy()->startOfDay(),
                direction: $transaction->direction,
                amountNative: $transaction->amount,
                currency: MercuryAccount::CURRENCY,
                // Mercury is USD-only, and USD is the base currency for the tenants on it. If a non-USD-base
                // tenant ever lands on Mercury this needs an FxRates lookup.
                amountBase: $transaction->amount,
                fxRateToBase: 1.0,
                category: $this->resolveCategory($transaction),
                counterpartyName: $transaction->counterpartyName,
                memo: $transaction->memo,
                rawPayload: $transaction->raw,
                source: 'mercury',
                externalId: $transaction->id,
            ),
            user: $this->user,
        )->execute();
    }

    /**
     * Money moving between accounts WE OWN is a transfer, never a spend.
     *
     * Mercury's `kind` catches the checking↔savings case (`internalTransfer`) but NOT a credit-card payment,
     * which it reports as the useless `kind: "other"` with the card's name as the counterparty. Left as
     * UNKNOWN, that would auto-draft a bill for a vendor called "Mercury Credit" every month — inventing an
     * expense out of paying down your own card.
     *
     * So: if the counterparty is one of our own Mercury accounts, it's a transfer. Both legs then post to
     * Suspense and cancel each other out, which is exactly right — no cash was created or destroyed, it just
     * moved.
     */
    private function resolveCategory(MercuryTransaction $transaction): BankTransactionCategoryEnum
    {
        if ($transaction->category !== BankTransactionCategoryEnum::UNKNOWN) {
            return $transaction->category;
        }

        $counterparty = $transaction->counterpartyName;

        if ($counterparty !== null && $this->isOwnAccountName($counterparty)) {
            return BankTransactionCategoryEnum::TRANSFER;
        }

        return $transaction->category;
    }

    private function isOwnAccountName(string $counterparty): bool
    {
        $ownNames = BankAccount::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source', 'mercury')
            ->where('is_deleted', false)
            ->pluck('account_name')
            ->map(fn (string $name): string => strtolower(trim($name)))
            ->all();

        return in_array(strtolower(trim($counterparty)), $ownNames, true);
    }

    private function resolveCursor(string $mercuryAccountId): Carbon
    {
        $stored = $this->company->get(ConfigurationEnum::SYNC_CURSOR->forAccount($mercuryAccountId));

        if (empty($stored)) {
            return Carbon::now()->subDays($this->initialLookbackDays);
        }

        return Carbon::parse((string) $stored)->subDays(self::RECHECK_WINDOW_DAYS);
    }
}
