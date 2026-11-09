<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Brex\DataTransferObject\BrexAccount;
use Kanvas\Connectors\Brex\DataTransferObject\BrexTransaction;
use Kanvas\Connectors\Brex\Enums\ConfigurationEnum;
use Kanvas\Connectors\Brex\Services\BrexTransactionService;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use RuntimeException;

/**
 * INGESTS ONLY — no journal entries; whether a movement gets its own JE is the matcher's call.
 *
 * Brex documents that transactions can appear late for a cursor/time window and tells callers to overlap the
 * lookback and de-duplicate by id. The overlap is free here: CreateBankTransactionAction is idempotent on
 * (source, external_id).
 *
 * Non-USD rows are skipped, not converted: the account is USD and there is no FX rate to book them at.
 */
class PullBrexTransactionsAction
{
    private const int RECHECK_WINDOW_DAYS = 3;

    public const int DEFAULT_LOOKBACK_DAYS = 90;

    public function __construct(
        public readonly BankAccount $bankAccount,
        public readonly ?UserInterface $user = null,
        public readonly int $initialLookbackDays = self::DEFAULT_LOOKBACK_DAYS,
    ) {
    }

    /**
     * @return list<BankTransaction>
     */
    public function execute(): array
    {
        $brexAccountId = $this->brexAccountId();
        $service = new BrexTransactionService($this->bankAccount->app, $this->bankAccount->company);
        $since = $this->resolveCursor($brexAccountId);

        $transactions = $brexAccountId === BrexAccount::CARD_EXTERNAL_ID
            ? $service->cardTransactions($since)
            : $service->cashTransactions($brexAccountId, $since);

        $landed = [];
        $newestPostedAt = null;

        foreach ($transactions as $transaction) {
            if (! $transaction->hasAmount() || $transaction->currency !== BrexAccount::CURRENCY) {
                continue;
            }

            $landed[] = $this->land($transaction);

            if ($newestPostedAt === null || $transaction->postedAt->greaterThan($newestPostedAt)) {
                $newestPostedAt = $transaction->postedAt;
            }
        }

        if ($newestPostedAt !== null) {
            $this->bankAccount->company->set(
                ConfigurationEnum::SYNC_CURSOR->forAccount($brexAccountId),
                $newestPostedAt->toIso8601String(),
            );
        }

        $this->bankAccount->last_synced_at = Carbon::now();
        $this->bankAccount->save();

        return $landed;
    }

    private function land(BrexTransaction $transaction): BankTransaction
    {
        return new CreateBankTransactionAction(
            data: new BankTransactionData(
                app: $this->bankAccount->app,
                company: $this->bankAccount->company,
                bankAccount: $this->bankAccount,
                postedAt: $transaction->postedAt,
                transactionDate: $transaction->postedAt->copy()->startOfDay(),
                direction: $transaction->direction,
                amountNative: $transaction->amount,
                currency: $transaction->currency,
                amountBase: $transaction->amount,
                fxRateToBase: 1.0,
                category: $transaction->category,
                counterpartyName: $transaction->counterpartyName,
                memo: $transaction->description !== '' ? $transaction->description : null,
                rawPayload: $transaction->raw,
                source: 'brex',
                externalId: $transaction->id,
            ),
            user: $this->user,
        )->execute();
    }

    private function brexAccountId(): string
    {
        $externalId = $this->bankAccount->external_id;

        if ($externalId === null || $this->bankAccount->source !== 'brex') {
            throw new RuntimeException(
                "BankAccount {$this->bankAccount->getId()} is not a Brex account — refusing to pull it."
            );
        }

        return $externalId;
    }

    private function resolveCursor(string $brexAccountId): Carbon
    {
        $stored = $this->bankAccount->company->get(ConfigurationEnum::SYNC_CURSOR->forAccount($brexAccountId));

        if (empty($stored)) {
            return Carbon::now()->subDays($this->initialLookbackDays);
        }

        return Carbon::parse((string) $stored)->subDays(self::RECHECK_WINDOW_DAYS);
    }
}
