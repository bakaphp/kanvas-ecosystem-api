<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryTransaction;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mercury\Services\MercuryTransactionService;
use Kanvas\Connectors\Mercury\Traits\MercuryBankAccountTrait;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;

/**
 * INGESTS ONLY — no journal entries. Whether a transaction gets its own JE is the matcher's call; posting
 * here would send every incoming payment to Suspense before anything could match it.
 *
 * The cursor overlap is intentional: Mercury can post a transaction with an earlier postedAt than one we've
 * seen, so a strict "newer than the cursor" filter would skip it forever. Re-ingesting is free (idempotent on
 * external_id).
 */
class PullMercuryTransactionsAction
{
    use MercuryBankAccountTrait;

    /** How far back to re-scan on every run, to catch late-posting transactions. */
    private const int RECHECK_WINDOW_DAYS = 3;

    /** First-ever pull for an account: how much history to bring in. */
    public const int DEFAULT_LOOKBACK_DAYS = 90;

    public function __construct(
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
        $mercuryAccountId = $this->mercuryAccountId();

        $service = $this->transactionService
            ?? new MercuryTransactionService($this->app(), $this->company());

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
            $this->company()->set(
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
        return new LandMercuryTransactionAction(
            bankAccount: $this->bankAccount,
            transaction: $transaction,
            user: $this->user,
        )->execute();
    }

    private function resolveCursor(string $mercuryAccountId): Carbon
    {
        $stored = $this->company()->get(ConfigurationEnum::SYNC_CURSOR->forAccount($mercuryAccountId));

        if (empty($stored)) {
            return Carbon::now()->subDays($this->initialLookbackDays);
        }

        return Carbon::parse((string) $stored)->subDays(self::RECHECK_WINDOW_DAYS);
    }
}
